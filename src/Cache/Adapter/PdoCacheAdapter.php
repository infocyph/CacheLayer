<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cache\Adapter;

use Infocyph\CacheLayer\Cache\Item\GenericCacheItem;
use PDO;
use PDOException;
use Psr\Cache\CacheItemInterface;
use RuntimeException;

final class PdoCacheAdapter extends AbstractCacheAdapter
{
    private readonly string $driver;
    private readonly string $ns;
    private readonly PDO $pdo;
    private readonly string $table;

    public function __construct(
        string $namespace = 'default',
        ?string $dsn = null,
        ?string $username = null,
        ?string $password = null,
        ?PDO $pdo = null,
        string $table = 'cachelayer_entries',
    ) {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            throw new RuntimeException('Invalid PDO cache table name.');
        }

        $this->ns = sanitize_cache_ns($namespace);
        $this->table = $table;
        $resolvedDsn = $dsn;
        if ($pdo === null && $resolvedDsn === null) {
            $resolvedDsn = 'sqlite:' . sys_get_temp_dir() . "/cache_{$this->ns}.sqlite";
        }

        $this->pdo = $pdo ?? new PDO((string) $resolvedDsn, $username, $password);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        $this->configureDriverDefaults();
        $this->createSchemaIfMissing();
    }

    public function clear(): bool
    {
        $stmt = $this->pdo->prepare("DELETE FROM {$this->table} WHERE ckey LIKE :prefix");
        $ok = $stmt->execute([':prefix' => $this->ns . ':%']);
        $this->deferred = [];
        return $ok;
    }

    public function count(): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM {$this->table}
             WHERE ckey LIKE :prefix
               AND (expires IS NULL OR expires > :now)",
        );
        $stmt->execute([
            ':prefix' => $this->ns . ':%',
            ':now' => time(),
        ]);

        return (int) $stmt->fetchColumn();
    }

    public function deleteItem(string $key): bool
    {
        $stmt = $this->pdo->prepare("DELETE FROM {$this->table} WHERE ckey = :k");
        return $stmt->execute([':k' => $this->map($key)]);
    }

    public function deleteItems(array $keys): bool
    {
        if ($keys === []) {
            return true;
        }

        $mapped = array_map($this->map(...), array_map(strval(...), $keys));
        $marks = implode(',', array_fill(0, count($mapped), '?'));
        $stmt = $this->pdo->prepare("DELETE FROM {$this->table} WHERE ckey IN ($marks)");
        return $stmt->execute($mapped);
    }

    public function getClient(): PDO
    {
        return $this->pdo;
    }

    public function getItem(string $key): GenericCacheItem
    {
        $stmt = $this->pdo->prepare(
            "SELECT payload, expires FROM {$this->table} WHERE ckey = :k LIMIT 1",
        );
        $stmt->execute([':k' => $this->map($key)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return new GenericCacheItem($this, $key);
        }

        $expiresAt = is_numeric($row['expires'] ?? null) ? (int) $row['expires'] : null;
        if (CachePayloadCodec::isExpired($expiresAt)) {
            $this->deleteItem($key);
            return new GenericCacheItem($this, $key);
        }

        $blob = base64_decode((string) ($row['payload'] ?? ''), true);
        if (!is_string($blob)) {
            $this->deleteItem($key);
            return new GenericCacheItem($this, $key);
        }

        $record = CachePayloadCodec::decode($blob);
        if ($record === null || CachePayloadCodec::isExpired($record['expires'])) {
            $this->deleteItem($key);
            return new GenericCacheItem($this, $key);
        }

        return new GenericCacheItem(
            $this,
            $key,
            $record['value'],
            true,
            CachePayloadCodec::toDateTime($record['expires']),
        );
    }

    public function hasItem(string $key): bool
    {
        return $this->getItem($key)->isHit();
    }

    public function multiFetch(array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        $mappedByLogical = [];
        foreach ($keys as $key) {
            $logical = (string) $key;
            $mapped = $this->map($logical);
            $mappedByLogical[$logical] = $mapped;
        }

        $rows = $this->fetchRowsByMappedKeys(array_values($mappedByLogical));
        $items = [];
        $staleMapped = [];

        foreach ($keys as $key) {
            $logical = (string) $key;
            $mapped = $mappedByLogical[$logical];
            $row = $rows[$mapped] ?? null;

            if (!is_array($row)) {
                $items[$logical] = new GenericCacheItem($this, $logical);
                continue;
            }

            $item = $this->hydrateItemFromRow($logical, $row);
            if ($item instanceof GenericCacheItem) {
                $items[$logical] = $item;
                continue;
            }

            $staleMapped[] = $mapped;
            $items[$logical] = new GenericCacheItem($this, $logical);
        }

        if ($staleMapped !== []) {
            $this->deleteMappedItems($staleMapped);
        }

        return $items;
    }

    public function save(CacheItemInterface $item): bool
    {
        if (!$this->supportsItem($item)) {
            return false;
        }

        $expires = CachePayloadCodec::expirationFromItem($item);
        if ($expires['ttl'] === 0) {
            return $this->deleteItem($item->getKey());
        }

        $params = [
            ':k' => $this->map($item->getKey()),
            ':p' => base64_encode(CachePayloadCodec::encode($item->get(), $expires['expiresAt'])),
            ':e' => $expires['expiresAt'],
        ];

        return $this->upsert($params, $this->map($item->getKey()));
    }

    protected function supportsItem(CacheItemInterface $item): bool
    {
        return $item instanceof GenericCacheItem;
    }

    private function configureDriverDefaults(): void
    {
        if ($this->driver !== 'sqlite') {
            return;
        }

        try {
            $this->pdo->exec('PRAGMA journal_mode=WAL; PRAGMA synchronous=NORMAL;');
        } catch (PDOException) {
            // Best effort sqlite tuning.
        }
    }

    private function createExpiresIndexIfMissing(): void
    {
        $index = "{$this->table}_expires_idx";

        try {
            if (in_array($this->driver, ['pgsql', 'sqlite', 'mysql', 'mariadb'], true)) {
                $this->pdo->exec("CREATE INDEX IF NOT EXISTS {$index} ON {$this->table}(expires)");
                return;
            }

            $this->pdo->exec("CREATE INDEX {$index} ON {$this->table}(expires)");
        } catch (PDOException) {
            // Retry once for engines that do not support IF NOT EXISTS on indexes.
            try {
                $this->pdo->exec("CREATE INDEX {$index} ON {$this->table}(expires)");
            } catch (PDOException) {
                // Ignore duplicate index/feature support errors.
            }
        }
    }

    private function createSchemaIfMissing(): void
    {
        $keyType = in_array($this->driver, ['mysql', 'mariadb'], true) ? 'VARCHAR(191)' : 'TEXT';

        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS {$this->table} (
                ckey {$keyType} PRIMARY KEY,
                payload TEXT NOT NULL,
                expires BIGINT NULL
            )",
        );

        $this->createExpiresIndexIfMissing();
    }

    /**
     * @param array<int, string> $mappedKeys
     */
    private function deleteMappedItems(array $mappedKeys): void
    {
        if ($mappedKeys === []) {
            return;
        }

        $marks = implode(',', array_fill(0, count($mappedKeys), '?'));
        $stmt = $this->pdo->prepare("DELETE FROM {$this->table} WHERE ckey IN ($marks)");
        $stmt->execute($mappedKeys);
    }

    /**
     * @param array<int, string> $mappedKeys
     * @return array<string, array{payload:string,expires:int|null}>
     */
    private function fetchRowsByMappedKeys(array $mappedKeys): array
    {
        if ($mappedKeys === []) {
            return [];
        }

        $marks = implode(',', array_fill(0, count($mappedKeys), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT ckey, payload, expires
             FROM {$this->table}
             WHERE ckey IN ($marks)",
        );
        $stmt->execute($mappedKeys);

        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = (string) ($row['ckey'] ?? '');
            if ($key === '' || !is_string($row['payload'] ?? null)) {
                continue;
            }

            $rows[$key] = [
                'payload' => $row['payload'],
                'expires' => is_numeric($row['expires'] ?? null) ? (int) $row['expires'] : null,
            ];
        }

        return $rows;
    }

    /**
     * @param array{payload:string,expires:int|null} $row
     */
    private function hydrateItemFromRow(string $key, array $row): ?GenericCacheItem
    {
        if (CachePayloadCodec::isExpired($row['expires'])) {
            return null;
        }

        $blob = base64_decode($row['payload'], true);
        if (!is_string($blob)) {
            return null;
        }

        $record = CachePayloadCodec::decode($blob);
        if ($record === null || CachePayloadCodec::isExpired($record['expires'])) {
            return null;
        }

        return new GenericCacheItem(
            $this,
            $key,
            $record['value'],
            true,
            CachePayloadCodec::toDateTime($record['expires']),
        );
    }

    private function map(string $key): string
    {
        return $this->ns . ':' . $key;
    }

    private function nativeUpsertSql(): ?string
    {
        return match ($this->driver) {
            'pgsql', 'sqlite' => "INSERT INTO {$this->table} (ckey, payload, expires)
                                  VALUES (:k, :p, :e)
                                  ON CONFLICT (ckey)
                                  DO UPDATE SET payload = EXCLUDED.payload, expires = EXCLUDED.expires",
            'mysql', 'mariadb' => "INSERT INTO {$this->table} (ckey, payload, expires)
                                   VALUES (:k, :p, :e)
                                   ON DUPLICATE KEY UPDATE payload = VALUES(payload), expires = VALUES(expires)",
            default => null,
        };
    }

    /**
     * @param array{':k':string,':p':string,':e':int|null} $params
     */
    private function upsert(array $params, string $mappedKey): bool
    {
        $nativeSql = $this->nativeUpsertSql();
        if ($nativeSql !== null) {
            $stmt = $this->pdo->prepare($nativeSql);
            return $stmt->execute($params);
        }

        $update = $this->pdo->prepare(
            "UPDATE {$this->table}
             SET payload = :p, expires = :e
             WHERE ckey = :k",
        );

        if (!$update->execute($params)) {
            return false;
        }

        if ($update->rowCount() > 0) {
            return true;
        }

        $insert = $this->pdo->prepare(
            "INSERT INTO {$this->table} (ckey, payload, expires)
             VALUES (:k, :p, :e)",
        );

        try {
            return $insert->execute($params);
        } catch (PDOException) {
            // Another process may have inserted concurrently.
            $updateByKey = $this->pdo->prepare(
                "UPDATE {$this->table}
                 SET payload = :p, expires = :e
                 WHERE ckey = :k",
            );

            return $updateByKey->execute([
                ':k' => $mappedKey,
                ':p' => $params[':p'],
                ':e' => $params[':e'],
            ]);
        }
    }
}
