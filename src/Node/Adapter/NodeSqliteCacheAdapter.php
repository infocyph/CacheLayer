<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Node\Adapter;

use Infocyph\CacheLayer\Cache\Adapter\AbstractCacheAdapter;
use Infocyph\CacheLayer\Cache\Adapter\AtomicCachePoolInterface;
use Infocyph\CacheLayer\Cache\Adapter\CachePayloadCodec;
use Infocyph\CacheLayer\Cache\Adapter\TagGenerationCacheInterface;
use Infocyph\CacheLayer\Cache\CacheInput;
use Infocyph\CacheLayer\Cache\Item\CacheItem;
use Infocyph\CacheLayer\Node\Exception\NodeCacheStorageException;
use PDO;
use PDOException;
use Psr\Cache\CacheItemInterface;
use Throwable;

final class NodeSqliteCacheAdapter extends AbstractCacheAdapter implements AtomicCachePoolInterface, TagGenerationCacheInterface
{
    private const string TABLE = 'cachelayer_node_entries';

    private readonly \PDOStatement $deleteStatement;

    private readonly \PDOStatement $lookupStatement;

    private readonly string $namespace;

    private readonly \PDOStatement $upsertStatement;

    private bool $ownsTransaction = false;

    /**
     * @throws \Infocyph\CacheLayer\Exceptions\CacheInvalidArgumentException
     * @throws NodeCacheStorageException
     */
    public function __construct(
        private readonly PDO $connection,
        string $namespace,
    ) {
        $this->namespace = CacheInput::namespace($namespace);
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

    #[\Override]
    public function atomicCompareAndSet(string $key, mixed $expected, CacheItemInterface $replacement): bool
    {
        if (!$this->supportsItem($replacement) || $replacement->getKey() !== $key) {
            return false;
        }
        $expiration = CachePayloadCodec::expirationFromItem($replacement);
        if ($expiration['ttl'] !== null && $expiration['ttl'] <= 0) {
            return false;
        }

        return $this->atomicTransaction(function () use ($key, $expected, $replacement): bool {
            $current = $this->getItem($key);
            if (!$current->isHit() || $current->get() !== $expected || !$this->atomicTagsCurrent($current)) {
                return false;
            }

            return $this->save($replacement);
        });
    }

    #[\Override]
    public function atomicGetAndDelete(string $key): CacheItemInterface
    {
        $this->discardDeferredKey($key);

        return $this->atomicTransaction(function () use ($key): CacheItemInterface {
            $current = $this->getItem($key);
            if (!$this->deleteItem($key)) {
                throw new NodeCacheStorageException('Unable to consume node SQLite cache entry.');
            }

            return $current->isHit() && $this->atomicTagsCurrent($current) ? $current : $this->genericMiss($key);
        });
    }

    #[\Override]
    public function atomicSetIfAbsent(CacheItemInterface $item): bool
    {
        if (!$this->supportsItem($item)) {
            return false;
        }
        $expiration = CachePayloadCodec::expirationFromItem($item);
        if ($expiration['ttl'] !== null && $expiration['ttl'] <= 0) {
            return false;
        }

        return $this->atomicTransaction(function () use ($item): bool {
            $current = $this->getItem($item->getKey());
            if ($current->isHit() && $this->atomicTagsCurrent($current)) {
                return false;
            }

            return $this->save($item);
        });
    }

    /**
     * @throws NodeCacheStorageException
     */
    #[\Override]
    public function clear(): bool
    {
        $this->assertWritableTransaction();

        try {
            $statement = $this->connection->prepare('DELETE FROM ' . self::TABLE . ' WHERE namespace = :namespace');
            $ok = $statement->execute([':namespace' => $this->namespace]);
            $this->deferred = [];

            return $ok;
        } catch (PDOException $exception) {
            throw $this->storageException('Unable to clear the node SQLite cache.', $exception);
        }
    }

    /**
     * @throws \Psr\Cache\InvalidArgumentException
     * @throws NodeCacheStorageException
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     */
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

    /**
     * @throws NodeCacheStorageException
     * @throws \Infocyph\CacheLayer\Exceptions\CacheInvalidArgumentException
     */
    #[\Override]
    public function deleteItem(string $key): bool
    {
        $this->discardDeferredKey($key);
        $this->assertWritableTransaction();

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
     * @phpstan-param array<array-key, string> $keys
     * @throws NodeCacheStorageException
     * @throws \Infocyph\CacheLayer\Exceptions\CacheInvalidArgumentException
     */
    #[\Override]
    public function deleteItems(array $keys): bool
    {
        $this->discardDeferredKeys($keys);
        if ($keys === []) {
            return true;
        }

        $this->assertWritableTransaction();

        try {
            $mapped = array_map($this->mapData(...), array_values($keys));
            $marks = implode(',', array_fill(0, count($mapped), '?'));
            $statement = $this->connection->prepare(
                'DELETE FROM ' . self::TABLE . " WHERE namespace = ? AND cache_key IN ({$marks})",
            );

            return $statement->execute([$this->namespace, ...$mapped]);
        } catch (PDOException $exception) {
            throw $this->storageException('Unable to delete node SQLite cache keys.', $exception);
        }
    }

    /**
     * @throws NodeCacheStorageException
     * @throws \Infocyph\CacheLayer\Exceptions\CacheInvalidArgumentException
     */
    #[\Override]
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
            return $this->genericMiss($key);
        }

        $record = $this->decodeRecordFromBlob($row['payload'], $key);
        if ($record === null) {
            return $this->genericMiss($key);
        }

        return $this->genericItemFromRecord($key, $record);
    }

    /**
     * @param list<string> $tags
     * @return array<int|string, string>
     * @throws NodeCacheStorageException
     */
    #[\Override]
    public function getTagGenerations(array $tags): array
    {
        $generations = $this->readTagGenerations($tags);
        $missing = [];
        foreach ($tags as $tag) {
            if (!isset($generations[$tag])) {
                $missing[$tag] = self::newGeneration();
            }
        }
        if ($missing !== [] && !$this->insertTagGenerationsIfMissing($missing)) {
            throw new NodeCacheStorageException('Unable to initialize node SQLite tag generations.');
        }

        $actual = $this->readTagGenerations($tags);
        foreach ($tags as $tag) {
            if (!isset($actual[$tag])) {
                throw new NodeCacheStorageException('Unable to initialize node SQLite tag generation.');
            }
        }

        return $actual;
    }

    /**
     * @throws NodeCacheStorageException
     * @throws \Infocyph\CacheLayer\Exceptions\CacheInvalidArgumentException
     */
    #[\Override]
    public function hasItem(string $key): bool
    {
        return $this->getItem($key)->isHit();
    }

    /**
     * @param array $keys The keys argument.
     * @phpstan-param list<string> $keys
     * @phpstan-return array<string, CacheItem>
     * @throws \Infocyph\CacheLayer\Exceptions\CacheInvalidArgumentException
     */
    #[\Override]
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
            if (!is_string($payload)) {
                $items[$key] = $this->genericMiss($key);

                continue;
            }
            $record = $this->decodeRecordFromBlob($payload, $key);
            if ($record === null) {
                $items[$key] = $this->genericMiss($key);

                continue;
            }
            $items[$key] = $this->genericItemFromRecord($key, $record);
        }

        return $items;
    }

    /**
     * @param list<string> $tags
     * @return array<int|string, string>
     */
    #[\Override]
    public function readTagGenerations(array $tags): array
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
            $generation = is_array($row) ? self::normalizeGeneration($row['payload'] ?? null) : null;
            if (is_array($row) && is_string($row['cache_key'] ?? null) && $generation !== null) {
                $stored[$row['cache_key']] = $generation;
            }
        }
        $generations = [];
        foreach ($tags as $tag) {
            $generation = $stored[$this->mapTag($tag)] ?? null;
            if (is_string($generation)) {
                $generations[$tag] = $generation;
            }
        }

        return $generations;
    }

    /**
     * @param list<string> $tags
     * @throws NodeCacheStorageException
     */
    #[\Override]
    public function rotateTagGenerations(array $tags): bool
    {
        $generations = [];
        foreach ($tags as $tag) {
            $generations[$tag] = self::newGeneration();
        }

        return $this->storeTagGenerations($generations);
    }

    /**
     * @throws \Psr\Cache\InvalidArgumentException
     * @throws NodeCacheStorageException
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     */
    #[\Override]
    public function save(CacheItemInterface $item): bool
    {
        $this->assertWritableTransaction();
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

    /**
     * @param array<string, CacheItemInterface> $items
     * @throws \Psr\Cache\InvalidArgumentException
     * @throws NodeCacheStorageException
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     */
    #[\Override]
    public function saveItems(array $items): bool
    {
        return $this->saveMany(array_values($items));
    }

    /**
     * @param array $items The items argument.
     * @phpstan-param list<CacheItemInterface> $items
     * @throws \Psr\Cache\InvalidArgumentException
     * @throws NodeCacheStorageException
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     */
    public function saveMany(array $items): bool
    {
        $this->assertWritableTransaction();
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
            $this->ownsTransaction = true;
            if (!$this->deleteItems($expired)) {
                $this->rollBack();

                return false;
            }
            if (!$this->upsertRows($rows)) {
                $this->rollBack();

                return false;
            }

            $committed = $this->connection->commit();
            $this->ownsTransaction = false;

            return $committed;
        } catch (PDOException $exception) {
            $this->rollBack();

            throw $this->storageException('Unable to store node SQLite cache entries.', $exception);
        } finally {
            $this->ownsTransaction = false;
        }
    }

    /**
     * @param array<int|string, string> $generations
     * @throws NodeCacheStorageException
     */
    #[\Override]
    public function storeTagGenerations(array $generations): bool
    {
        $this->assertWritableTransaction();
        $rows = [];
        foreach ($generations as $tag => $generation) {
            $tag = (string) $tag;
            if (!self::isGeneration($generation)) {
                return false;
            }
            $rows[] = [$this->namespace, $this->mapTag($tag), strtolower($generation), null];
        }

        return $this->upsertRows($rows);
    }

    /** @throws NodeCacheStorageException */
    private function atomicTagsCurrent(CacheItem $item): bool
    {
        $tags = $item->getTagGenerations();
        if ($tags === []) {
            return true;
        }
        $actual = $this->readTagGenerations(array_map(static fn(int|string $tag): string => (string) $tag, array_keys($tags)));
        foreach ($tags as $tag => $generation) {
            if (($actual[$tag] ?? null) !== $generation) {
                return false;
            }
        }

        return true;
    }

    /**
     * @template T
     * @param callable(): T $operation
     * @return T
     * @throws Throwable
     */
    private function atomicTransaction(callable $operation): mixed
    {
        if ($this->ownsTransaction || $this->connection->inTransaction()) {
            throw new NodeCacheStorageException('Node atomic operations require an owned transaction.');
        }
        if ($this->connection->exec('BEGIN IMMEDIATE') === false) {
            throw new NodeCacheStorageException('Unable to begin node atomic transaction.');
        }
        $this->ownsTransaction = true;
        try {
            $result = $operation();
            if ($this->connection->exec('COMMIT') === false) {
                throw new NodeCacheStorageException('Unable to commit node atomic transaction.');
            }

            return $result;
        } catch (Throwable $failure) {
            try {
                $this->connection->exec('ROLLBACK');
            } catch (Throwable) {
                // Keep the original failure.
            }

            throw $failure;
        } finally {
            $this->ownsTransaction = false;
        }
    }

    /**
     * @throws NodeCacheStorageException
     */
    private function assertWritableTransaction(): void
    {
        if ($this->connection->inTransaction() && !$this->ownsTransaction) {
            throw new NodeCacheStorageException(
                'Node SQLite cache mutations cannot join a caller-owned transaction.',
            );
        }
    }

    /**
     * @throws NodeCacheStorageException
     */
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

    /**
     * @param array<int|string, string> $generations
     * @throws NodeCacheStorageException
     */
    private function insertTagGenerationsIfMissing(array $generations): bool
    {
        $this->assertWritableTransaction();
        foreach ($generations as $tag => $generation) {
            $statement = $this->connection->prepare(
                'INSERT INTO ' . self::TABLE
                . ' (namespace, cache_key, payload, expires_at) VALUES (?, ?, ?, NULL) '
                . 'ON CONFLICT(namespace, cache_key) DO NOTHING',
            );
            if (!$statement->execute([
                $this->namespace,
                $this->mapTag((string) $tag),
                strtolower($generation),
            ])) {
                return false;
            }
        }

        return true;
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
        if ($this->ownsTransaction && $this->connection->inTransaction()) {
            $this->connection->rollBack();
        }
        $this->ownsTransaction = false;
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
