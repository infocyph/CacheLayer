<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Node\Adapter;

use Infocyph\CacheLayer\Cache\Adapter\AbstractCacheAdapter;
use Infocyph\CacheLayer\Cache\Adapter\CachePayloadCodec;
use Infocyph\CacheLayer\Cache\Item\GenericCacheItem;
use Infocyph\CacheLayer\Node\Exception\NodeCacheStorageException;
use PDO;
use PDOException;
use Psr\Cache\CacheItemInterface;

final class NodeSqliteCacheAdapter extends AbstractCacheAdapter
{
    private const string TABLE = 'cachelayer_node_entries';

    private readonly \PDOStatement $deleteStatement;

    private readonly \PDOStatement $lookupStatement;

    private readonly \PDOStatement $upsertStatement;

    public function __construct(
        private readonly PDO $connection,
        private readonly string $namespace,
    ) {
        $this->createSchemaIfMissing();
        $this->deleteStatement = $connection->prepare(
            'DELETE FROM ' . self::TABLE . ' WHERE namespace = :namespace AND cache_key = :cache_key',
        );
        $this->lookupStatement = $connection->prepare(
            'SELECT payload, expires_at FROM ' . self::TABLE . ' WHERE namespace = :namespace '
            . 'AND cache_key = :cache_key AND (expires_at IS NULL OR expires_at > :current_time) LIMIT 1',
        );
        $this->upsertStatement = $connection->prepare(
            'INSERT INTO ' . self::TABLE . ' (namespace, cache_key, payload, expires_at) '
            . 'VALUES (:namespace, :cache_key, :payload, :expires_at) '
            . 'ON CONFLICT(namespace, cache_key) DO UPDATE SET '
            . 'payload = excluded.payload, expires_at = excluded.expires_at',
        );
    }

    public function clear(): bool
    {
        try {
            $statement = $this->connection->prepare('DELETE FROM ' . self::TABLE . ' WHERE namespace = :namespace');
            $ok = $statement->execute([':namespace' => $this->namespace]);
            $this->deferred = [];

            return $ok;
        } catch (PDOException $exception) {
            throw $this->storageException('Unable to clear the node SQLite cache.', $exception);
        }
    }

    #[\Override]
    public function commit(): bool
    {
        $items = array_values($this->deferred);
        if (!$this->saveMany($items)) {
            return false;
        }

        $this->deferred = [];

        return true;
    }

    public function connection(): PDO
    {
        return $this->connection;
    }

    public function count(): int
    {
        try {
            $statement = $this->connection->prepare(
                'SELECT COUNT(*) FROM ' . self::TABLE . ' WHERE namespace = :namespace '
                . 'AND (expires_at IS NULL OR expires_at > :current_time)',
            );
            $statement->execute([':namespace' => $this->namespace, ':current_time' => time()]);
            $count = $statement->fetchColumn();

            return is_numeric($count) ? max(0, (int) $count) : 0;
        } catch (PDOException $exception) {
            throw $this->storageException('Unable to count node SQLite cache entries.', $exception);
        }
    }

    public function deleteItem(string $key): bool
    {
        try {
            return $this->deleteStatement->execute([':namespace' => $this->namespace, ':cache_key' => $key]);
        } catch (PDOException $exception) {
            throw $this->storageException("Unable to delete node SQLite cache key '{$key}'.", $exception);
        }
    }

    /**
     * @param array $keys The keys argument.
     * @phpstan-param list<string> $keys
     */
    public function deleteItems(array $keys): bool
    {
        if ($keys === []) {
            return true;
        }

        try {
            $this->connection->beginTransaction();
            foreach ($keys as $key) {
                $this->deleteStatement->execute([':namespace' => $this->namespace, ':cache_key' => $key]);
            }
            $this->connection->commit();

            return true;
        } catch (PDOException $exception) {
            $this->rollBack();

            throw $this->storageException('Unable to delete node SQLite cache keys.', $exception);
        }
    }

    public function getItem(string $key): GenericCacheItem
    {
        try {
            $this->lookupStatement->execute([
                ':namespace' => $this->namespace,
                ':cache_key' => $key,
                ':current_time' => time(),
            ]);
            $row = $this->lookupStatement->fetch();
        } catch (PDOException $exception) {
            throw $this->storageException("Unable to read node SQLite cache key '{$key}'.", $exception);
        }

        if (!is_array($row) || !is_string($row['payload'] ?? null)) {
            return new GenericCacheItem($this, $key);
        }

        $record = CachePayloadCodec::decode($row['payload']);
        if ($record === null || CachePayloadCodec::isExpired($record['expires'])) {
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

    /**
     * @param array $keys The keys argument.
     * @phpstan-param list<string> $keys
     * @phpstan-return array<string, GenericCacheItem>
     */
    public function multiFetch(array $keys): array
    {
        return $this->multiFetchItems($keys, $this->getItem(...));
    }

    public function save(CacheItemInterface $item): bool
    {
        return $this->saveMany([$item]);
    }

    /**
     * @param array $items The items argument.
     * @phpstan-param list<CacheItemInterface> $items
     */
    public function saveMany(array $items): bool
    {
        foreach ($items as $item) {
            if (!$this->supportsItem($item)) {
                return false;
            }
        }

        if ($items === []) {
            return true;
        }

        try {
            $this->connection->beginTransaction();
            foreach ($items as $item) {
                $this->persistItem($item);
            }
            $this->connection->commit();

            return true;
        } catch (PDOException $exception) {
            $this->rollBack();

            throw $this->storageException('Unable to store node SQLite cache entries.', $exception);
        }
    }

    protected function supportsItem(CacheItemInterface $item): bool
    {
        return $item instanceof GenericCacheItem;
    }

    private function createSchemaIfMissing(): void
    {
        try {
            $this->connection->exec(
                'CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' ('
                . 'namespace TEXT NOT NULL, cache_key TEXT NOT NULL, payload BLOB NOT NULL, '
                . 'expires_at INTEGER, PRIMARY KEY (namespace, cache_key)) WITHOUT ROWID',
            );
            $this->connection->exec(
                'CREATE INDEX IF NOT EXISTS cachelayer_node_entries_expiry_idx '
                . 'ON ' . self::TABLE . ' (namespace, expires_at)',
            );
        } catch (PDOException $exception) {
            throw $this->storageException('Unable to initialize the node SQLite cache schema.', $exception);
        }
    }

    private function persistItem(CacheItemInterface $item): void
    {
        $expires = CachePayloadCodec::expirationFromItem($item);
        if ($expires['ttl'] === 0) {
            $this->deleteStatement->execute([':namespace' => $this->namespace, ':cache_key' => $item->getKey()]);

            return;
        }

        $this->upsertStatement->bindValue(':namespace', $this->namespace, PDO::PARAM_STR);
        $this->upsertStatement->bindValue(':cache_key', $item->getKey(), PDO::PARAM_STR);
        $this->upsertStatement->bindValue(':payload', CachePayloadCodec::encode($item->get(), $expires['expiresAt']), PDO::PARAM_LOB);
        $this->upsertStatement->bindValue(':expires_at', $expires['expiresAt'], $expires['expiresAt'] === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $this->upsertStatement->execute();
    }

    private function rollBack(): void
    {
        if ($this->connection->inTransaction()) {
            $this->connection->rollBack();
        }
    }

    private function storageException(string $message, PDOException $exception): NodeCacheStorageException
    {
        return new NodeCacheStorageException($message, 0, $exception);
    }
}
