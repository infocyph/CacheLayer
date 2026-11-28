<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cache\Adapter;

use Infocyph\CacheLayer\Cache\CacheRecord;
use PDO;
use PDOException;
use Psr\Cache\CacheItemInterface;
use RuntimeException;
use Throwable;

/** @internal PDO atomic protocol kept separate from normal cache mechanics. */
trait PdoAtomicOperations
{
    /**
     * @throws RuntimeException
     * @throws Throwable
     */
    public function atomicCompareAndSet(
        string $key,
        mixed $expected,
        CacheItemInterface $replacement,
    ): bool {
        if (!$this->supportsAtomicCache() || !$this->supportsItem($replacement)) {
            return false;
        }
        $expiration = CachePayloadCodec::expirationFromItem($replacement);
        if ($expiration['ttl'] !== null && $expiration['ttl'] <= 0) {
            return false;
        }

        return $this->atomicTransaction(function () use ($key, $expected, $replacement, $expiration): bool {
            $row = $this->atomicFetchRow($key);
            $record = $row === null ? null : $this->atomicRecordFromRow($key, $row);
            if (!$record instanceof CacheRecord
                || !$this->atomicRecordTagsAreCurrent($record)
                || $record->value !== $expected) {
                return false;
            }

            return $this->atomicUpdateExisting(
                $key,
                $this->encodeItem($replacement, $expiration['expiresAt']),
                $expiration['expiresAt'],
            );
        });
    }

    /**
     * @throws \Infocyph\CacheLayer\Exceptions\CacheInvalidArgumentException
     * @throws RuntimeException
     * @throws Throwable
     */
    public function atomicGetAndDelete(string $key): CacheItemInterface
    {
        $this->discardDeferredKey($key);

        if (!$this->supportsAtomicCache()) {
            return $this->genericMiss($key);
        }

        return $this->atomicTransaction(function () use ($key): CacheItemInterface {
            $row = $this->atomicFetchRow($key);
            if ($row === null) {
                return $this->genericMiss($key);
            }

            $record = $this->atomicRecordFromRow($key, $row);
            $this->deleteItem($key);
            if (!$record instanceof CacheRecord || !$this->atomicRecordTagsAreCurrent($record)) {
                return $this->genericMiss($key);
            }

            return $this->genericItemFromRecord($key, $record);
        });
    }

    /**
     * @throws RuntimeException
     * @throws Throwable
     */
    public function atomicSetIfAbsent(CacheItemInterface $item): bool
    {
        if (!$this->supportsAtomicCache() || !$this->supportsItem($item)) {
            return false;
        }
        $expiration = CachePayloadCodec::expirationFromItem($item);
        if ($expiration['ttl'] !== null && $expiration['ttl'] <= 0) {
            return false;
        }

        return $this->atomicInsertTransaction(function () use ($item, $expiration): bool {
            $key = $item->getKey();
            $row = $this->atomicFetchRow($key);
            $record = $row === null ? null : $this->atomicRecordFromRow($key, $row);
            if ($record instanceof CacheRecord && $this->atomicRecordTagsAreCurrent($record)) {
                return false;
            }

            $payload = $this->encodeItem($item, $expiration['expiresAt']);
            if ($row !== null) {
                return $this->atomicUpdateExisting($key, $payload, $expiration['expiresAt']);
            }

            return $this->atomicInsertIfMissing($key, $payload, $expiration['expiresAt']);
        });
    }

    public function supportsAtomicCache(): bool
    {
        return in_array($this->driver, ['sqlite', 'pgsql', 'mysql', 'mariadb'], true);
    }

    /** @return array{payload:string, expires:int|null}|null */
    private function atomicFetchRow(string $key): ?array
    {
        $suffix = in_array($this->driver, ['pgsql', 'mysql', 'mariadb'], true) ? ' FOR UPDATE' : '';
        $statement = $this->pdo->prepare(
            "SELECT payload, expires FROM {$this->table} "
            . "WHERE namespace = ? AND kind = ? AND cache_key = ?{$suffix}",
        );
        $statement->execute([$this->namespace, self::KIND_DATA, $key]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }

        $payload = $row['payload'] ?? null;
        if (is_resource($payload)) {
            $payload = stream_get_contents($payload);
        }
        if (!is_string($payload)) {
            return null;
        }

        return [
            'payload' => $payload,
            'expires' => is_numeric($row['expires'] ?? null) ? (int) $row['expires'] : null,
        ];
    }

    private function atomicInsertIfMissing(string $key, string $payload, ?int $expires): bool
    {
        if (in_array($this->driver, ['pgsql', 'sqlite'], true)) {
            $sql = "INSERT INTO {$this->table} (namespace, kind, cache_key, payload, expires) "
                . 'VALUES (?, ?, ?, ?, ?) ON CONFLICT (namespace, kind, cache_key) DO NOTHING';
        } else {
            $sql = "INSERT IGNORE INTO {$this->table} "
                . '(namespace, kind, cache_key, payload, expires) VALUES (?, ?, ?, ?, ?)';
        }
        $statement = $this->pdo->prepare($sql);
        if ($this->driver === 'pgsql') {
            $statement->bindValue(1, $this->namespace, PDO::PARAM_STR);
            $statement->bindValue(2, self::KIND_DATA, PDO::PARAM_STR);
            $statement->bindValue(3, $key, PDO::PARAM_STR);
            $statement->bindValue(4, $payload, PDO::PARAM_LOB);
            $this->bindExpiration($statement, 5, $expires);
            $statement->execute();
        } else {
            $statement->execute([$this->namespace, self::KIND_DATA, $key, $payload, $expires]);
        }

        return $statement->rowCount() === 1;
    }

    /** @param array{payload:string, expires:int|null} $row */
    private function atomicRecordFromRow(string $key, array $row): ?CacheRecord
    {
        if (CachePayloadCodec::isExpired($row['expires'])) {
            return null;
        }

        $record = $this->decodeRecordFromBlob($row['payload'], $key);

        return $record instanceof CacheRecord ? $record : null;
    }

    private function atomicRecordTagsAreCurrent(CacheRecord $record): bool
    {
        if ($record->tags === []) {
            return true;
        }

        $rows = $this->fetchRows(self::KIND_TAG, array_map(static fn(int|string $tag): string => (string) $tag, array_keys($record->tags)));
        foreach ($record->tags as $tag => $generation) {
            $tag = (string) $tag;
            if (($rows[$tag]['payload'] ?? null) !== $generation) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param callable(): bool $callback
     * @throws Throwable
     */
    private function atomicInsertTransaction(callable $callback): bool
    {
        for ($attempt = 0; ; ++$attempt) {
            try {
                return $this->atomicTransaction($callback);
            } catch (PDOException $failure) {
                if ($attempt >= 7
                    || !in_array($this->driver, ['mysql', 'mariadb'], true)
                    || ($failure->errorInfo[0] ?? null) !== '40001'
                    || !in_array($failure->errorInfo[1] ?? null, [1213, '1213'], true)
                    || $this->pdo->inTransaction()) {
                    throw $failure;
                }

                // InnoDB error 1213 rolls back the whole transaction. A fresh
                // transaction must re-read state; never retry unknown outcomes.
                usleep(1_000 * ($attempt + 1));
            }
        }
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     * @throws RuntimeException
     * @throws Throwable
     */
    private function atomicTransaction(callable $callback): mixed
    {
        if ($this->pdo->inTransaction()) {
            throw new RuntimeException('Atomic PDO cache operations require ownership of the PDO transaction.');
        }

        $sqlite = $this->driver === 'sqlite';
        if ($sqlite) {
            $this->pdo->exec('BEGIN IMMEDIATE');
        } else {
            $this->pdo->beginTransaction();
        }

        try {
            $result = $callback();
            if ($sqlite) {
                $this->pdo->exec('COMMIT');
            } else {
                $this->pdo->commit();
            }

            return $result;
        } catch (Throwable $failure) {
            try {
                if ($sqlite) {
                    $this->pdo->exec('ROLLBACK');
                } elseif ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
            } catch (Throwable) {
                // Preserve the original backend failure.
            }

            throw $failure;
        }
    }

    private function atomicUpdateExisting(string $key, string $payload, ?int $expires): bool
    {
        $statement = $this->pdo->prepare(
            "UPDATE {$this->table} SET payload = ?, expires = ? "
            . 'WHERE namespace = ? AND kind = ? AND cache_key = ?',
        );

        if ($this->driver !== 'pgsql') {
            return $statement->execute([$payload, $expires, $this->namespace, self::KIND_DATA, $key]);
        }

        $statement->bindValue(1, $payload, PDO::PARAM_LOB);
        $this->bindExpiration($statement, 2, $expires);
        $statement->bindValue(3, $this->namespace, PDO::PARAM_STR);
        $statement->bindValue(4, self::KIND_DATA, PDO::PARAM_STR);
        $statement->bindValue(5, $key, PDO::PARAM_STR);

        return $statement->execute();
    }
}
