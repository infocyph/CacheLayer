<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cache\Adapter;

use Infocyph\CacheLayer\Cache\CacheInput;
use Infocyph\CacheLayer\Cache\Item\CacheItem;
use Infocyph\CacheLayer\Support\OptionalCassandra;
use Psr\Cache\CacheItemInterface;
use RuntimeException;
use Traversable;

final class ScyllaDbCacheAdapter extends AbstractCacheAdapter implements TagGenerationCacheInterface
{
    private const int WRITE_BATCH_SIZE = 50;

    private readonly string $metadataTable;

    private readonly string $ns;

    private readonly string $qualifiedTable;

    /** @var array<string, mixed> */
    private array $preparedStatements = [];

    public function __construct(
        private readonly object $session,
        string $keyspace = 'cachelayer',
        string $table = 'cachelayer_entries',
        string $namespace = 'default',
        private readonly int $bucketCount = 128,
    ) {
        if (!$this->supportsSessionMethod('execute')) {
            throw new RuntimeException('ScyllaDbCacheAdapter requires session method `execute()`.');
        }

        $this->ns = CacheInput::namespace($namespace);
        $resolvedTable = self::validateIdentifier($table, 'table');
        $resolvedKeyspace = self::validateIdentifier($keyspace, 'keyspace');
        $this->qualifiedTable = $resolvedKeyspace . '.' . $resolvedTable;
        $this->metadataTable = $this->qualifiedTable . '_metadata';
        if ($bucketCount < 1 || $bucketCount > 1024) {
            throw new RuntimeException('ScyllaDB bucket count must be between 1 and 1024.');
        }

        $this->createSchemaIfMissing();
    }

    public function clear(): bool
    {
        for ($bucket = 0; $bucket < $this->bucketCount; $bucket++) {
            $this->executeCql(
                "DELETE FROM {$this->qualifiedTable} WHERE ns = ? AND bucket = ?",
                [$this->ns, $bucket],
            );
            $this->executeCql(
                "DELETE FROM {$this->metadataTable} WHERE ns = ? AND bucket = ?",
                [$this->ns, $bucket],
            );
        }
        $this->deferred = [];

        return true;
    }

    public function deleteItem(string $key): bool
    {
        $this->discardDeferredKey($key);
        $this->executeCql(
            "DELETE FROM {$this->qualifiedTable} WHERE ns = ? AND bucket = ? AND ckey = ?",
            [$this->ns, $this->bucket($key), $this->mapData($key)],
        );

        return true;
    }

    /**
     * @param array $keys The keys argument.
     * @phpstan-param list<string> $keys
     */
    public function deleteItems(array $keys): bool
    {
        $this->discardDeferredKeys($keys);
        foreach ($this->groupByBucket($keys) as $bucket => $group) {
            $marks = implode(',', array_fill(0, count($group), '?'));
            $this->executeCql(
                "DELETE FROM {$this->qualifiedTable} WHERE ns = ? AND bucket = ? AND ckey IN ({$marks})",
                [$this->ns, $bucket, ...array_map($this->mapData(...), $group)],
            );
        }

        return true;
    }

    public function getItem(string $key): CacheItem
    {
        $row = $this->firstRow(
            "SELECT payload, expires FROM {$this->qualifiedTable} WHERE ns = ? AND bucket = ? AND ckey = ? LIMIT 1",
            [$this->ns, $this->bucket($key), $this->mapData($key)],
        );

        if ($row === null) {
            return $this->genericMiss($key);
        }

        $expiresAt = ScyllaValueNormalizer::expiry($row['expires'] ?? null);
        if ($expiresAt !== null && $expiresAt <= time()) {
            return $this->genericMiss($key);
        }

        $payload = ScyllaValueNormalizer::string($row['payload'] ?? null);

        return $this->genericFromBlobWithInvalidator(
            $key,
            $payload,
            static fn(): bool => true,
        );
    }

    /**
     * @param list<string> $tags
     * @return array<string, string>
     */
    #[\Override]
    public function getTagGenerations(array $tags): array
    {
        $generations = $this->readTagGenerations($tags);
        foreach ($tags as $tag) {
            if (isset($generations[$tag])) {
                continue;
            }

            $this->executeCql(
                "INSERT INTO {$this->metadataTable} (ns, bucket, tag, generation) "
                . 'VALUES (?, ?, ?, ?) IF NOT EXISTS',
                [$this->ns, $this->bucket($tag), $tag, self::newGeneration()],
            );
        }

        $actual = $this->readTagGenerations($tags);
        foreach ($tags as $tag) {
            if (!isset($actual[$tag])) {
                throw new RuntimeException('Unable to initialize ScyllaDB tag generation.');
            }
        }

        return $actual;
    }

    public function hasItem(string $key): bool
    {
        return $this->getItem($key)->isHit();
    }

    /**
     * @param array $keys The keys argument.
     * @phpstan-param list<string> $keys
     * @phpstan-return array<string, CacheItem>
     */
    public function multiFetch(array $keys): array
    {
        $items = [];
        foreach ($this->groupByBucket($keys) as $bucket => $group) {
            $items += $this->fetchBucketItems($bucket, $group);
        }

        return $items;
    }

    /** @param list<string> $tags */
    #[\Override]
    public function readTagGenerations(array $tags): array
    {
        $generations = [];
        foreach ($this->groupByBucket($tags) as $bucket => $group) {
            $marks = implode(',', array_fill(0, count($group), '?'));
            $rows = $this->queryRows(
                "SELECT tag, generation FROM {$this->metadataTable} "
                . "WHERE ns = ? AND bucket = ? AND tag IN ({$marks})",
                [$this->ns, $bucket, ...$group],
            );
            foreach ($rows as $row) {
                $tag = ScyllaValueNormalizer::string($row['tag'] ?? null);
                $generation = ScyllaValueNormalizer::string($row['generation'] ?? null);
                $generation = self::normalizeGeneration($generation);
                if ($tag !== null && $generation !== null) {
                    $generations[$tag] = $generation;
                }
            }
        }

        return $generations;
    }

    /** @param list<string> $tags */
    #[\Override]
    public function rotateTagGenerations(array $tags): bool
    {
        $generations = [];
        foreach ($tags as $tag) {
            $generations[$tag] = self::newGeneration();
        }

        return $this->storeTagGenerations($generations);
    }

    public function save(CacheItemInterface $item): bool
    {
        return $this->saveEncoded($item, function (CacheItemInterface $saveItem, array $expires): bool {
            $cql = "INSERT INTO {$this->qualifiedTable} (ns, bucket, ckey, payload, expires) "
                . 'VALUES (?, ?, ?, ?, ?)';
            $arguments = [
                $this->ns,
                $this->bucket($saveItem->getKey()),
                $this->mapData($saveItem->getKey()),
                OptionalCassandra::blob($this->encodeItem($saveItem, $expires['expiresAt'])),
                OptionalCassandra::bigint($expires['expiresAt']),
            ];
            if ($expires['ttl'] !== null) {
                $cql .= ' USING TTL ?';
                $arguments[] = $expires['ttl'];
            }
            $this->executeCql($cql, $arguments);

            return true;
        });
    }

    /** @param array<string, CacheItemInterface> $items */
    public function saveItems(array $items): bool
    {
        $active = [];
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
            $active[$this->bucket($item->getKey())][] = [
                $item,
                $expiration['expiresAt'],
                $expiration['ttl'],
            ];
        }
        if (!$this->deleteItems($expired)) {
            return false;
        }
        foreach ($active as $bucket => $group) {
            foreach (array_chunk($group, self::WRITE_BATCH_SIZE) as $chunk) {
                $this->saveBucket($bucket, $chunk);
            }
        }

        return true;
    }

    /** @param array<string, string> $generations */
    #[\Override]
    public function storeTagGenerations(array $generations): bool
    {
        foreach ($generations as $tag => $generation) {
            $tag = (string) $tag;
            if (!self::isGeneration($generation)) {
                return false;
            }
            $this->executeCql(
                "INSERT INTO {$this->metadataTable} (ns, bucket, tag, generation) VALUES (?, ?, ?, ?)",
                [$this->ns, $this->bucket($tag), $tag, strtolower($generation)],
            );
        }

        return true;
    }

    private static function validateIdentifier(string $value, string $label): string
    {
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $value)) {
            throw new RuntimeException(sprintf('Invalid ScyllaDB %s name `%s`.', $label, $value));
        }

        return $value;
    }

    private function bucket(string $key): int
    {
        return hexdec(substr(hash('xxh3', $key), 0, 8)) % $this->bucketCount;
    }

    /**
     * @param string $method The method argument.
     * @param array $arguments The arguments argument.
     * @phpstan-param array<int, mixed> $arguments
     */
    private function callSession(string $method, array $arguments): mixed
    {
        $callable = [$this->session, $method];
        if (!is_callable($callable)) {
            throw new RuntimeException(
                sprintf('ScyllaDbCacheAdapter requires session method `%s()`.', $method),
            );
        }

        return $callable(...$arguments);
    }

    private function createSchemaIfMissing(): void
    {
        $this->executeCql(
            "CREATE TABLE IF NOT EXISTS {$this->qualifiedTable} (
                ns text,
                bucket int,
                ckey text,
                payload blob,
                expires bigint,
                PRIMARY KEY ((ns, bucket), ckey)
            )",
        );
        $this->executeCql(
            "CREATE TABLE IF NOT EXISTS {$this->metadataTable} (
                ns text,
                bucket int,
                tag text,
                generation text,
                PRIMARY KEY ((ns, bucket), tag)
            )",
        );
    }

    /**
     * @param string $cql The cql argument.
     * @param array $arguments The arguments argument.
     * @phpstan-param array<int, mixed> $arguments
     */
    private function executeCql(string $cql, array $arguments = []): mixed
    {
        $statement = $this->statementFor($cql);
        $options = $this->executionOptions($arguments);

        return $this->callSession('execute', [$statement, $options]);
    }

    /**
     * @param array $arguments The arguments argument.
     * @phpstan-param array<int, mixed> $arguments
     */
    private function executionOptions(array $arguments): mixed
    {
        return OptionalCassandra::executionOptions($arguments);
    }

    /**
     * @param list<string> $keys
     * @return array<string, CacheItem>
     */
    private function fetchBucketItems(int $bucket, array $keys): array
    {
        $marks = implode(',', array_fill(0, count($keys), '?'));
        $rows = $this->queryRows(
            "SELECT ckey, payload, expires FROM {$this->qualifiedTable} "
            . "WHERE ns = ? AND bucket = ? AND ckey IN ({$marks})",
            [$this->ns, $bucket, ...array_map($this->mapData(...), $keys)],
        );
        $byKey = [];
        foreach ($rows as $row) {
            $physical = ScyllaValueNormalizer::string($row['ckey'] ?? null);
            if ($physical !== null) {
                $byKey[$physical] = $row;
            }
        }

        $items = [];
        foreach ($keys as $key) {
            $row = $byKey[$this->mapData($key)] ?? null;
            $payload = is_array($row) ? ScyllaValueNormalizer::string($row['payload'] ?? null) : null;
            $record = $payload === null ? null : $this->decodeRecordFromBlob($payload, $key);
            $items[$key] = $record === null
                ? $this->genericMiss($key)
                : $this->genericItemFromRecord($key, $record);
        }

        return $items;
    }

    /**
     * @param string $cql The cql argument.
     * @param array $arguments The arguments argument.
     * @phpstan-param array<int, mixed> $arguments
     * @phpstan-return array<string, mixed>|null
     */
    private function firstRow(string $cql, array $arguments = []): ?array
    {
        foreach ($this->queryRows($cql, $arguments) as $row) {
            return $row;
        }

        return null;
    }

    /**
     * @param list<string> $keys
     * @return array<int, list<string>>
     */
    private function groupByBucket(array $keys): array
    {
        $groups = [];
        foreach ($keys as $key) {
            $groups[$this->bucket($key)][] = $key;
        }

        return $groups;
    }

    private function mapData(string $key): string
    {
        return 'd:' . $key;
    }

    /**
     * @param array $rows The rows argument.
     * @phpstan-param array<mixed, mixed> $rows
     * @phpstan-return array<int, array<string, mixed>>
     */
    private function normalizeRows(array $rows): array
    {
        $normalized = [];
        foreach ($rows as $row) {
            $assoc = AdapterValueNormalizer::fromJsonOrArrayLike($row);
            if ($assoc !== null) {
                $normalized[] = $assoc;
            }
        }

        return $normalized;
    }

    /**
     * @param string $cql The cql argument.
     * @param array $arguments The arguments argument.
     * @phpstan-param array<int, mixed> $arguments
     * @phpstan-return array<int, array<string, mixed>>
     */
    private function queryRows(string $cql, array $arguments = []): array
    {
        $result = $this->executeCql($cql, $arguments);

        if (is_array($result)) {
            return $this->normalizeRows($result);
        }

        if ($result instanceof Traversable) {
            return $this->normalizeRows(iterator_to_array($result));
        }

        if (is_object($result) && is_callable([$result, 'toArray'])) {
            $rows = $result->toArray();

            return is_array($rows) ? $this->normalizeRows($rows) : [];
        }

        return [];
    }

    /** @param list<array{0:CacheItemInterface, 1:int|null, 2:int|null}> $items */
    private function saveBucket(int $bucket, array $items): void
    {
        $inserts = [];
        $arguments = [];
        foreach ($items as [$item, $expiresAt, $ttl]) {
            $inserts[] = "INSERT INTO {$this->qualifiedTable} "
                . '(ns, bucket, ckey, payload, expires) VALUES (?, ?, ?, ?, ?)'
                . ($ttl === null ? ';' : ' USING TTL ?;');
            array_push(
                $arguments,
                $this->ns,
                $bucket,
                $this->mapData($item->getKey()),
                OptionalCassandra::blob($this->encodeItem($item, $expiresAt)),
                OptionalCassandra::bigint($expiresAt),
            );
            if ($ttl !== null) {
                $arguments[] = $ttl;
            }
        }
        $this->executeCql('BEGIN UNLOGGED BATCH ' . implode(' ', $inserts) . ' APPLY BATCH', $arguments);
    }

    private function statementFor(string $cql): mixed
    {
        if ($this->supportsSessionMethod('prepare')) {
            if (!array_key_exists($cql, $this->preparedStatements)) {
                $this->preparedStatements[$cql] = $this->callSession('prepare', [$cql]);
            }

            return $this->preparedStatements[$cql];
        }

        return OptionalCassandra::simpleStatement($cql);
    }

    private function supportsSessionMethod(string $method): bool
    {
        return method_exists($this->session, $method) || is_callable([$this->session, $method]);
    }
}
