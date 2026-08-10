<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cache\Adapter;

use Infocyph\CacheLayer\Cache\Item\CacheItem;
use Psr\Cache\CacheItemInterface;
use RuntimeException;

final class PdoCacheAdapter extends AbstractCacheAdapter
{
    private const int BATCH_SIZE = 250;

    private const string DEFAULT_SQLITE_DIR = 'cachelayer/pdo';

    private readonly string $driver;

    private readonly string $namespace;

    private readonly \PDO $pdo;

    private readonly string $table;

    public function __construct(
        string $namespace = 'default',
        ?string $dsn = null,
        ?string $username = null,
        ?string $password = null,
        ?\PDO $pdo = null,
        string $table = 'cachelayer_entries',
        bool $initializeSchema = true,
    ) {
        if (preg_match('/^[A-Za-z0-9_]+$/D', $table) !== 1) {
            throw new RuntimeException('Invalid PDO cache table name.');
        }

        $this->namespace = sanitize_cache_ns($namespace);
        $this->table = $table;
        $resolvedDsn = $dsn ?? 'sqlite:' . self::defaultSqliteFileForNamespace($this->namespace);
        $this->pdo = $pdo ?? new \PDO($resolvedDsn, $username, $password);
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $driver = $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $this->driver = is_string($driver) ? $driver : '';
        if ($this->driver === 'sqlite') {
            $this->pdo->exec('PRAGMA journal_mode=WAL; PRAGMA synchronous=NORMAL; PRAGMA busy_timeout=5000;');
        }
        if ($initializeSchema) {
            PdoCacheSchema::install($this->pdo, $this->table);
        }
    }

    public static function defaultSqliteFileForNamespace(string $namespace): string
    {
        $directory = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, self::DEFAULT_SQLITE_DIR);
        if (is_link($directory)) {
            throw new RuntimeException("Refusing symlinked SQLite cache directory: {$directory}");
        }
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException("Unable to create SQLite cache directory: {$directory}");
        }
        if (!is_writable($directory)) {
            throw new RuntimeException("SQLite cache directory is not writable: {$directory}");
        }

        return $directory . DIRECTORY_SEPARATOR . 'cache_' . sanitize_cache_ns($namespace) . '.sqlite';
    }

    public function clear(): bool
    {
        $statement = $this->pdo->prepare("DELETE FROM {$this->table} WHERE ckey LIKE ?");
        $cleared = $statement->execute([$this->namespace . ':%']);
        $this->deferred = [];

        return $cleared;
    }

    public function count(): int
    {
        $statement = $this->pdo->prepare(
            "SELECT COUNT(*) FROM {$this->table}
             WHERE ckey LIKE ? AND (expires IS NULL OR expires > ?)",
        );
        $statement->execute([$this->namespace . ':d:%', time()]);
        $count = $statement->fetchColumn();

        return is_numeric($count) ? max(0, (int) $count) : 0;
    }

    public function deleteItem(string $key): bool
    {
        $statement = $this->pdo->prepare("DELETE FROM {$this->table} WHERE ckey = ?");

        return $statement->execute([$this->mapData($key)]);
    }

    /** @param list<string> $keys */
    public function deleteItems(array $keys): bool
    {
        return $this->deleteMapped(array_map($this->mapData(...), $keys));
    }

    public function getClient(): \PDO
    {
        return $this->pdo;
    }

    public function getItem(string $key): CacheItem
    {
        $statement = $this->pdo->prepare(
            "SELECT payload, expires FROM {$this->table} WHERE ckey = ? LIMIT 1",
        );
        $statement->execute([$this->mapData($key)]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return $this->genericMiss($key);
        }

        $item = $this->hydrate($key, $row);
        if ($item !== null) {
            return $item;
        }
        $this->deleteItem($key);

        return $this->genericMiss($key);
    }

    /**
     * @param list<string> $tags
     * @return array<string, int>
     */
    #[\Override]
    public function getTagVersions(array $tags): array
    {
        $mapped = [];
        foreach ($tags as $tag) {
            $mapped[$tag] = $this->mapTag($tag);
        }
        $rows = $this->fetchRows(array_values($mapped));
        $versions = [];
        foreach ($mapped as $tag => $physical) {
            $row = $rows[$physical] ?? null;
            $payload = is_array($row) ? $row['payload'] : null;
            $versions[$tag] = is_string($payload) && ctype_digit($payload) ? (int) $payload : 0;
        }

        return $versions;
    }

    public function hasItem(string $key): bool
    {
        return $this->getItem($key)->isHit();
    }

    /** @param list<string> $tags */
    #[\Override]
    public function incrementTagVersions(array $tags): bool
    {
        if ($tags === []) {
            return true;
        }

        $sql = match ($this->driver) {
            'pgsql', 'sqlite' => "INSERT INTO {$this->table} (ckey, payload, expires) VALUES (?, '1', NULL)
                ON CONFLICT (ckey) DO UPDATE SET payload = CAST({$this->table}.payload AS INTEGER) + 1",
            'mysql', 'mariadb' => "INSERT INTO {$this->table} (ckey, payload, expires) VALUES (?, '1', NULL)
                ON DUPLICATE KEY UPDATE payload = CAST(payload AS UNSIGNED) + 1",
            default => null,
        };
        if ($sql === null) {
            return $this->incrementTagsWithTransaction($tags);
        }

        $statement = $this->pdo->prepare($sql);
        foreach ($tags as $tag) {
            if (!$statement->execute([$this->mapTag($tag)])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<string> $keys
     * @return array<string, CacheItem>
     */
    public function multiFetch(array $keys): array
    {
        $mapped = [];
        foreach ($keys as $key) {
            $mapped[$key] = $this->mapData($key);
        }
        $rows = $this->fetchRows(array_values($mapped));
        $items = [];
        $stale = [];
        foreach ($mapped as $logical => $physical) {
            $row = $rows[$physical] ?? null;
            $item = is_array($row) ? $this->hydrate($logical, $row) : null;
            $items[$logical] = $item ?? $this->genericMiss($logical);
            if (is_array($row) && $item === null) {
                $stale[] = $physical;
            }
        }
        $this->deleteMapped($stale);

        return $items;
    }

    public function save(CacheItemInterface $item): bool
    {
        if (!$this->supportsItem($item)) {
            return false;
        }
        $expiration = CachePayloadCodec::expirationFromItem($item);
        if ($expiration['ttl'] !== null && $expiration['ttl'] <= 0) {
            return $this->deleteItem($item->getKey());
        }

        return $this->upsertRows([[
            $this->mapData($item->getKey()),
            base64_encode($this->encodeItem($item, $expiration['expiresAt'])),
            $expiration['expiresAt'],
        ]]);
    }

    /** @param array<string, CacheItemInterface> $items */
    public function saveItems(array $items): bool
    {
        $rows = [];
        $expired = [];
        foreach ($items as $item) {
            if (!$this->supportsItem($item)) {
                return false;
            }
            $expiration = CachePayloadCodec::expirationFromItem($item);
            if ($expiration['ttl'] !== null && $expiration['ttl'] <= 0) {
                $expired[] = $this->mapData($item->getKey());

                continue;
            }
            $rows[] = [
                $this->mapData($item->getKey()),
                base64_encode($this->encodeItem($item, $expiration['expiresAt'])),
                $expiration['expiresAt'],
            ];
        }

        return $this->deleteMapped($expired) && $this->upsertRows($rows);
    }

    /** @param list<string> $mappedKeys */
    private function deleteMapped(array $mappedKeys): bool
    {
        foreach (array_chunk($mappedKeys, self::BATCH_SIZE) as $chunk) {
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            if (!$this->pdo->prepare("DELETE FROM {$this->table} WHERE ckey IN ({$marks})")->execute($chunk)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<string> $mappedKeys
     * @return array<string, array{payload:string, expires:int|null}>
     */
    private function fetchRows(array $mappedKeys): array
    {
        $rows = [];
        foreach (array_chunk($mappedKeys, self::BATCH_SIZE) as $chunk) {
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            $statement = $this->pdo->prepare(
                "SELECT ckey, payload, expires FROM {$this->table} WHERE ckey IN ({$marks})",
            );
            $statement->execute($chunk);
            foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                if (!is_array($row) || !is_string($row['ckey'] ?? null) || !is_string($row['payload'] ?? null)) {
                    continue;
                }
                $rows[$row['ckey']] = [
                    'payload' => $row['payload'],
                    'expires' => is_numeric($row['expires'] ?? null) ? (int) $row['expires'] : null,
                ];
            }
        }

        return $rows;
    }

    /** @param array<array-key, mixed> $row */
    private function hydrate(string $key, array $row): ?CacheItem
    {
        $expiresAt = is_numeric($row['expires']) ? (int) $row['expires'] : null;
        if (CachePayloadCodec::isExpired($expiresAt) || !is_string($row['payload'])) {
            return null;
        }

        $record = $this->decodeRecordFromBase64($row['payload']);

        return $record === null ? null : $this->genericItemFromRecord($key, $record);
    }

    /** @param list<string> $tags */
    private function incrementTagsWithTransaction(array $tags): bool
    {
        $this->pdo->beginTransaction();

        try {
            foreach ($tags as $tag) {
                $key = $this->mapTag($tag);
                $update = $this->pdo->prepare(
                    "UPDATE {$this->table} SET payload = CAST(payload AS INTEGER) + 1 WHERE ckey = ?",
                );
                $update->execute([$key]);
                if ($update->rowCount() === 0) {
                    $this->pdo->prepare(
                        "INSERT INTO {$this->table} (ckey, payload, expires) VALUES (?, '1', NULL)",
                    )->execute([$key]);
                }
            }

            return $this->pdo->commit();
        } catch (\PDOException $failure) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $failure;
        }
    }

    private function mapData(string $key): string
    {
        return $this->namespace . ':d:' . $key;
    }

    private function mapTag(string $tag): string
    {
        return $this->namespace . ':m:tag:' . $tag;
    }

    /** @param list<array{0:string, 1:string, 2:int|null}> $rows */
    private function upsertChunk(array $rows): bool
    {
        if (!in_array($this->driver, ['pgsql', 'sqlite', 'mysql', 'mariadb'], true)) {
            $this->pdo->beginTransaction();

            try {
                foreach ($rows as $row) {
                    $this->upsertGeneric($row);
                }

                return $this->pdo->commit();
            } catch (\PDOException $failure) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }

                throw $failure;
            }
        }

        $values = implode(',', array_fill(0, count($rows), '(?, ?, ?)'));
        $suffix = in_array($this->driver, ['pgsql', 'sqlite'], true)
            ? 'ON CONFLICT (ckey) DO UPDATE SET payload = EXCLUDED.payload, expires = EXCLUDED.expires'
            : 'ON DUPLICATE KEY UPDATE payload = VALUES(payload), expires = VALUES(expires)';
        $parameters = [];
        foreach ($rows as $row) {
            array_push($parameters, ...$row);
        }

        return $this->pdo->prepare(
            "INSERT INTO {$this->table} (ckey, payload, expires) VALUES {$values} {$suffix}",
        )->execute($parameters);
    }

    /** @param array{0:string, 1:string, 2:int|null} $row */
    private function upsertGeneric(array $row): void
    {
        $update = $this->pdo->prepare("UPDATE {$this->table} SET payload = ?, expires = ? WHERE ckey = ?");
        $update->execute([$row[1], $row[2], $row[0]]);
        if ($update->rowCount() === 0) {
            $this->pdo->prepare(
                "INSERT INTO {$this->table} (ckey, payload, expires) VALUES (?, ?, ?)",
            )->execute($row);
        }
    }

    /** @param list<array{0:string, 1:string, 2:int|null}> $rows */
    private function upsertRows(array $rows): bool
    {
        if ($rows === []) {
            return true;
        }
        foreach (array_chunk($rows, self::BATCH_SIZE) as $chunk) {
            if (!$this->upsertChunk($chunk)) {
                return false;
            }
        }

        return true;
    }
}
