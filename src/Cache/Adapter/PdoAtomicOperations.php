<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cache\Adapter;

use Infocyph\CacheLayer\Cache\CacheRecord;
use PDO;
use Psr\Cache\CacheItemInterface;
use RuntimeException;
use Throwable;

/** @internal PDO atomic protocol kept separate from normal cache mechanics. */
trait PdoAtomicOperations
{
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
            $record = $row === null ? null : $this->atomicRecordFromRow($row);
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

    public function atomicGetAndDelete(string $key): CacheItemInterface
    {
        if (!$this->supportsAtomicCache()) {
            return $this->genericMiss($key);
        }

        return $this->atomicTransaction(function () use ($key): CacheItemInterface {
            $row = $this->atomicFetchRow($key);
            if ($row === null) {
                return $this->genericMiss($key);
            }

            $record = $this->atomicRecordFromRow($row);
            $this->deleteItem($key);
            if (!$record instanceof CacheRecord || !$this->atomicRecordTagsAreCurrent($record)) {
                return $this->genericMiss($key);
            }

            return $this->genericItemFromRecord($key, $record);
        });
    }

    public function atomicSetIfAbsent(CacheItemInterface $item): bool
    {
        if (!$this->supportsAtomicCache() || !$this->supportsItem($item)) {
            return false;
        }
        $expiration = CachePayloadCodec::expirationFromItem($item);
        if ($expiration['ttl'] !== null && $expiration['ttl'] <= 0) {
            return false;
        }

        return $this->atomicTransaction(function () use ($item, $expiration): bool {
            $key = $item->getKey();
            $row = $this->atomicFetchRow($key);
            $record = $row === null ? null : $this->atomicRecordFromRow($row);
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
        $statement->execute([$this->namespace, self::KIND_DATA, $key, $payload, $expires]);

        return $statement->rowCount() === 1;
    }

    /** @param array{payload:string, expires:int|null} $row */
    private function atomicRecordFromRow(array $row): ?CacheRecord
    {
        if (CachePayloadCodec::isExpired($row['expires'])) {
            return null;
        }

        $record = $this->decodeRecordFromBlob($row['payload']);

        return $record instanceof CacheRecord ? $record : null;
    }

    private function atomicRecordTagsAreCurrent(CacheRecord $record): bool
    {
        if ($record->tags === []) {
            return true;
        }

        $rows = $this->fetchRows(self::KIND_TAG, array_keys($record->tags));
        foreach ($record->tags as $tag => $generation) {
            if (($rows[$tag]['payload'] ?? null) !== $generation) {
                return false;
            }
        }

        return true;
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
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
            if ($sqlite) {
                $this->pdo->exec('ROLLBACK');
            } else {
                $this->pdo->rollBack();
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

        return $statement->execute([$payload, $expires, $this->namespace, self::KIND_DATA, $key]);
    }
}
