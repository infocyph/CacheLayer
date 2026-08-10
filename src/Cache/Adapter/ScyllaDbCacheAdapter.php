<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cache\Adapter;

use Cassandra\ExecutionOptions;
use Cassandra\SimpleStatement;
use Infocyph\CacheLayer\Cache\Item\CacheItem;
use Psr\Cache\CacheItemInterface;
use RuntimeException;
use Throwable;
use Traversable;

final class ScyllaDbCacheAdapter extends AbstractCacheAdapter
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

        $this->ns = sanitize_cache_ns($namespace);
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

    public function count(): int
    {
        $now = time();
        $count = 0;
        for ($bucket = 0; $bucket < $this->bucketCount; $bucket++) {
            $rows = $this->queryRows(
                "SELECT expires FROM {$this->qualifiedTable} WHERE ns = ? AND bucket = ?",
                [$this->ns, $bucket],
            );
            foreach ($rows as $row) {
                $expiresAt = $this->normalizeExpiry($row['expires'] ?? null);
                if ($expiresAt === null || $expiresAt > $now) {
                    $count++;
                }
            }
        }

        return $count;
    }

    public function deleteItem(string $key): bool
    {
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

        $expiresAt = $this->normalizeExpiry($row['expires'] ?? null);
        if ($expiresAt !== null && $expiresAt <= time()) {
            return $this->genericDeleteAndMiss($key);
        }

        $payload = $this->normalizeString($row['payload'] ?? null);

        return $this->genericFromBase64($key, $payload);
    }

    /**
     * @param list<string> $tags
     * @return array<string, int>
     */
    #[\Override]
    public function getTagVersions(array $tags): array
    {
        $versions = array_fill_keys($tags, 0);
        foreach ($this->groupByBucket($tags) as $bucket => $group) {
            $marks = implode(',', array_fill(0, count($group), '?'));
            $rows = $this->queryRows(
                "SELECT tag, version FROM {$this->metadataTable} "
                . "WHERE ns = ? AND bucket = ? AND tag IN ({$marks})",
                [$this->ns, $bucket, ...$group],
            );
            foreach ($rows as $row) {
                $tag = $this->normalizeString($row['tag'] ?? null);
                $version = $row['version'] ?? null;
                if ($tag !== null && is_numeric($version)) {
                    $versions[$tag] = max(0, (int) $version);
                }
            }
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
        foreach ($tags as $tag) {
            $this->executeCql(
                "UPDATE {$this->metadataTable} SET version = version + 1 WHERE ns = ? AND bucket = ? AND tag = ?",
                [$this->ns, $this->bucket($tag), $tag],
            );
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
        $items = [];
        foreach ($this->groupByBucket($keys) as $bucket => $group) {
            $marks = implode(',', array_fill(0, count($group), '?'));
            $rows = $this->queryRows(
                "SELECT ckey, payload, expires FROM {$this->qualifiedTable} "
                . "WHERE ns = ? AND bucket = ? AND ckey IN ({$marks})",
                [$this->ns, $bucket, ...array_map($this->mapData(...), $group)],
            );
            $byKey = [];
            foreach ($rows as $row) {
                $physical = $this->normalizeString($row['ckey'] ?? null);
                if ($physical !== null) {
                    $byKey[$physical] = $row;
                }
            }
            foreach ($group as $key) {
                $row = $byKey[$this->mapData($key)] ?? null;
                $payload = is_array($row) ? $this->normalizeString($row['payload'] ?? null) : null;
                $items[$key] = $this->genericFromBase64($key, $payload);
            }
        }

        return $items;
    }

    public function save(CacheItemInterface $item): bool
    {
        return $this->saveEncoded($item, function (CacheItemInterface $saveItem, array $expires): bool {
            $this->executeCql(
                "INSERT INTO {$this->qualifiedTable} (ns, bucket, ckey, payload, expires) VALUES (?, ?, ?, ?, ?)",
                [
                    $this->ns,
                    $this->bucket($saveItem->getKey()),
                    $this->mapData($saveItem->getKey()),
                    base64_encode($this->encodeItem($saveItem, $expires['expiresAt'])),
                    $expires['expiresAt'],
                ],
            );

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
            $active[$this->bucket($item->getKey())][] = [$item, $expiration['expiresAt']];
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
                payload text,
                expires bigint,
                PRIMARY KEY ((ns, bucket), ckey)
            )",
        );
        $this->executeCql(
            "CREATE TABLE IF NOT EXISTS {$this->metadataTable} (
                ns text,
                bucket int,
                tag text,
                version counter,
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

        try {
            return $this->callSession('execute', [$statement, $options]);
        } catch (Throwable) {
            return $this->callSession('execute', [$statement]);
        }
    }

    /**
     * @param array $arguments The arguments argument.
     * @phpstan-param array<int, mixed> $arguments
     */
    private function executionOptions(array $arguments): mixed
    {
        $options = ['arguments' => $arguments];
        if (class_exists(ExecutionOptions::class)) {
            return new ExecutionOptions($options);
        }

        return $options;
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

    private function normalizeExpiry(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value) || (is_string($value) && is_numeric($value))) {
            return (int) $value;
        }

        if (is_object($value) && is_callable([$value, '__toString'])) {
            $stringValue = (string) $value;
            if (is_numeric($stringValue)) {
                return (int) $stringValue;
            }
        }

        return null;
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

    private function normalizeString(mixed $value): ?string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_object($value) && is_callable([$value, '__toString'])) {
            return (string) $value;
        }

        return null;
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

    /** @param list<array{0:CacheItemInterface, 1:int|null}> $items */
    private function saveBucket(int $bucket, array $items): void
    {
        $inserts = [];
        $arguments = [];
        foreach ($items as [$item, $expiresAt]) {
            $inserts[] = "INSERT INTO {$this->qualifiedTable} "
                . '(ns, bucket, ckey, payload, expires) VALUES (?, ?, ?, ?, ?);';
            array_push(
                $arguments,
                $this->ns,
                $bucket,
                $this->mapData($item->getKey()),
                base64_encode($this->encodeItem($item, $expiresAt)),
                $expiresAt,
            );
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

        if (class_exists(SimpleStatement::class)) {
            return new SimpleStatement($cql);
        }

        return $cql;
    }

    private function supportsSessionMethod(string $method): bool
    {
        return method_exists($this->session, $method) || is_callable([$this->session, $method]);
    }
}
