<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Node\Adapter;

use Infocyph\CacheLayer\Cache\Adapter\AbstractCacheAdapter;
use Infocyph\CacheLayer\Cache\Adapter\CachePayloadCodec;
use Infocyph\CacheLayer\Cache\Item\CacheItem;
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
            return $this->deleteStatement->execute([
                ':namespace' => $this->namespace,
                ':cache_key' => $this->mapData($key),
            ]);
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
            $mapped = array_map($this->mapData(...), $keys);
            $marks = implode(',', array_fill(0, count($mapped), '?'));
            $statement = $this->connection->prepare(
                'DELETE FROM ' . self::TABLE . " WHERE namespace = ? AND cache_key IN ({$marks})",
            );

            return $statement->execute([$this->namespace, ...$mapped]);
        } catch (PDOException $exception) {
            $this->rollBack();

            throw $this->storageException('Unable to delete node SQLite cache keys.', $exception);
        }
    }

    public function getItem(string $key): CacheItem
    {
        try {
            $this->lookupStatement->execute([
                ':namespace' => $this->namespace,
                ':cache_key' => $this->mapData($key),
                ':current_time' => time(),
            ]);
            $row = $this->lookupStatement->fetch();
        } catch (PDOException $exception) {
            throw $this->storageException("Unable to read node SQLite cache key '{$key}'.", $exception);
        }

        if (!is_array($row) || !is_string($row['payload'] ?? null)) {
            return new CacheItem($this, $key);
        }

        $record = $this->decodeRecordFromBlob($row['payload']);
        if ($record === null) {
            return new CacheItem($this, $key);
        }

        return $this->genericItemFromRecord($key, $record);
    }

    /**
     * @param list<string> $tags
     * @return array<string, int>
     */
    #[\Override]
    public function getTagVersions(array $tags): array
    {
        if ($tags === []) {
            return [];
        }
        $keys = array_map($this->mapTag(...), $tags);
        $marks = implode(',', array_fill(0, count($keys), '?'));
        $statement = $this->connection->prepare(
            'SELECT cache_key, payload FROM ' . self::TABLE
            . " WHERE namespace = ? AND cache_key IN ({$marks})",
        );
        $statement->execute([$this->namespace, ...$keys]);
        $stored = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (is_array($row) && is_string($row['cache_key'] ?? null)) {
                $stored[$row['cache_key']] = $row['payload'] ?? null;
            }
        }
        $versions = [];
        foreach ($tags as $tag) {
            $value = $stored[$this->mapTag($tag)] ?? null;
            $versions[$tag] = is_string($value) && ctype_digit($value) ? (int) $value : 0;
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
        $statement = $this->connection->prepare(
            'INSERT INTO ' . self::TABLE . ' (namespace, cache_key, payload, expires_at) '
            . "VALUES (?, ?, '1', NULL) ON CONFLICT(namespace, cache_key) "
            . 'DO UPDATE SET payload = CAST(payload AS INTEGER) + 1',
        );
        foreach ($tags as $tag) {
            if (!$statement->execute([$this->namespace, $this->mapTag($tag)])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array $keys The keys argument.
     * @phpstan-param list<string> $keys
     * @phpstan-return array<string, CacheItem>
     */
    public function multiFetch(array $keys): array
    {
        if ($keys === []) {
            return [];
        }
        $mapped = array_map($this->mapData(...), $keys);
        $marks = implode(',', array_fill(0, count($mapped), '?'));
        $statement = $this->connection->prepare(
            'SELECT cache_key, payload FROM ' . self::TABLE
            . " WHERE namespace = ? AND cache_key IN ({$marks})"
            . ' AND (expires_at IS NULL OR expires_at > ?)',
        );
        $statement->execute([$this->namespace, ...$mapped, time()]);
        $rows = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (is_array($row) && is_string($row['cache_key'] ?? null) && is_string($row['payload'] ?? null)) {
                $rows[$row['cache_key']] = $row['payload'];
            }
        }
        $items = [];
        foreach ($keys as $key) {
            $payload = $rows[$this->mapData($key)] ?? null;
            $items[$key] = is_string($payload)
                ? $this->genericFromBlob($key, $payload)
                : $this->genericMiss($key);
        }

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

        try {
            $this->upsertStatement->bindValue(':namespace', $this->namespace, PDO::PARAM_STR);
            $this->upsertStatement->bindValue(':cache_key', $this->mapData($item->getKey()), PDO::PARAM_STR);
            $this->upsertStatement->bindValue(
                ':payload',
                $this->encodeItem($item, $expiration['expiresAt']),
                PDO::PARAM_LOB,
            );
            $this->upsertStatement->bindValue(
                ':expires_at',
                $expiration['expiresAt'],
                $expiration['expiresAt'] === null ? PDO::PARAM_NULL : PDO::PARAM_INT,
            );

            return $this->upsertStatement->execute();
        } catch (PDOException $exception) {
            throw $this->storageException('Unable to store a node SQLite cache entry.', $exception);
        }
    }

    /** @param array<string, CacheItemInterface> $items */
    public function saveItems(array $items): bool
    {
        return $this->saveMany(array_values($items));
    }

    /**
     * @param array $items The items argument.
     * @phpstan-param list<CacheItemInterface> $items
     */
    public function saveMany(array $items): bool
    {
        $rows = [];
        $expired = [];
        foreach ($items as $item) {
            if (!$this->supportsItem($item)) {
                return false;
            }
            $expiration = CachePayloadCodec::expirationFromItem($item);
            if ($expiration['ttl'] !== null && $expiration['ttl'] <= 0) {
                $expired[] = $item->getKey();

                continue;
            }
            $rows[] = [
                $this->namespace,
                $this->mapData($item->getKey()),
                $this->encodeItem($item, $expiration['expiresAt']),
                $expiration['expiresAt'],
            ];
        }

        if ($rows === [] && $expired === []) {
            return true;
        }

        try {
            $this->connection->beginTransaction();
            if ($expired !== [] && !$this->deleteItems($expired)) {
                $this->rollBack();

                return false;
            }
            if ($rows !== [] && !$this->upsertRows($rows)) {
                $this->rollBack();

                return false;
            }

            return $this->connection->commit();
        } catch (PDOException $exception) {
            $this->rollBack();

            throw $this->storageException('Unable to store node SQLite cache entries.', $exception);
        }
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

    private function mapData(string $key): string
    {
        return 'd:' . $key;
    }

    private function mapTag(string $tag): string
    {
        return 'm:tag:' . $tag;
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

    /** @param list<array{0:string, 1:string, 2:string, 3:int|null}> $rows */
    private function upsertRows(array $rows): bool
    {
        foreach (array_chunk($rows, 200) as $chunk) {
            $values = implode(',', array_fill(0, count($chunk), '(?, ?, ?, ?)'));
            $statement = $this->connection->prepare(
                'INSERT INTO ' . self::TABLE . " (namespace, cache_key, payload, expires_at) VALUES {$values} "
                . 'ON CONFLICT(namespace, cache_key) DO UPDATE SET '
                . 'payload = excluded.payload, expires_at = excluded.expires_at',
            );
            $parameters = [];
            foreach ($chunk as $row) {
                array_push($parameters, ...$row);
            }
            if (!$statement->execute($parameters)) {
                return false;
            }
        }

        return true;
    }
}
