<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cache\Adapter;

use Infocyph\CacheLayer\Cache\Item\GenericCacheItem;
use PDO;
use Psr\Cache\CacheItemInterface;
use RuntimeException;

final class PostgresCacheAdapter extends AbstractCacheAdapter
{
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
            throw new RuntimeException('Invalid PostgreSQL cache table name.');
        }

        $this->ns = sanitize_cache_ns($namespace);
        $this->table = $table;
        $this->pdo = $pdo ?? new PDO(
            $dsn ?? 'pgsql:host=127.0.0.1;port=5432;dbname=postgres',
            $username,
            $password,
        );
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS {$this->table} (
                ckey TEXT PRIMARY KEY,
                payload TEXT NOT NULL,
                expires BIGINT NULL
            )",
        );
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS {$this->table}_expires_idx ON {$this->table}(expires)");
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
        $ok = true;
        foreach ($keys as $key) {
            $ok = $this->deleteItem((string) $key) && $ok;
        }

        return $ok;
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

        $item = new GenericCacheItem($this, $key);
        $item->set($record['value']);
        if ($record['expires'] !== null) {
            $item->expiresAt(CachePayloadCodec::toDateTime($record['expires']));
        }

        return $item;
    }

    public function hasItem(string $key): bool
    {
        return $this->getItem($key)->isHit();
    }

    public function multiFetch(array $keys): array
    {
        $items = [];
        foreach ($keys as $key) {
            $items[(string) $key] = $this->getItem((string) $key);
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

        $blob = base64_encode(CachePayloadCodec::encode($item->get(), $expires['expiresAt']));
        $stmt = $this->pdo->prepare(
            "INSERT INTO {$this->table} (ckey, payload, expires)
             VALUES (:k, :p, :e)
             ON CONFLICT (ckey)
             DO UPDATE SET payload = EXCLUDED.payload, expires = EXCLUDED.expires",
        );

        return $stmt->execute([
            ':k' => $this->map($item->getKey()),
            ':p' => $blob,
            ':e' => $expires['expiresAt'],
        ]);
    }

    protected function supportsItem(CacheItemInterface $item): bool
    {
        return $item instanceof GenericCacheItem;
    }

    private function map(string $key): string
    {
        return $this->ns . ':' . $key;
    }
}
