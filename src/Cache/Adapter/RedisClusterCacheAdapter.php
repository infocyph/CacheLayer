<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cache\Adapter;

use Infocyph\CacheLayer\Cache\CacheInput;
use Infocyph\CacheLayer\Cache\CacheRecord;
use Infocyph\CacheLayer\Cache\Item\CacheItem;
use Psr\Cache\CacheItemInterface;
use RuntimeException;

final class RedisClusterCacheAdapter extends AbstractCacheAdapter implements AtomicCachePoolInterface
{
    use RedisClusterAtomicOperations;

    private const int BUCKET_COUNT = 128;

    private const int PIPELINE_MODE = 2;

    private readonly object $cluster;

    private readonly string $namespace;

    /** @param list<string> $seeds */
    public function __construct(
        string $namespace = 'default',
        array $seeds = ['127.0.0.1:6379'],
        float $timeout = 1.0,
        float $readTimeout = 1.0,
        bool $persistent = false,
        ?object $client = null,
    ) {
        if ($client === null) {
            if (!class_exists(\RedisCluster::class)) {
                throw new RuntimeException('phpredis RedisCluster support is not loaded');
            }
            $client = new \RedisCluster(null, $seeds, $timeout, $readTimeout, $persistent);
        }
        foreach (['del', 'eval', 'exists', 'get', 'mget', 'mset', 'multi', 'set', 'setex'] as $method) {
            if (!method_exists($client, $method)) {
                throw new RuntimeException("Redis Cluster client must expose {$method}().");
            }
        }
        $this->namespace = CacheInput::namespace($namespace);
        $this->cluster = $client;
    }

    public function clear(): bool
    {
        for ($bucket = 0; $bucket < self::BUCKET_COUNT; ++$bucket) {
            if (!$this->call('set', $this->generationKey($bucket), self::newGeneration())) {
                return false;
            }
        }
        $this->deferred = [];

        return true;
    }

    public function deleteItem(string $key): bool
    {
        return $this->call('del', $this->mapData($key)) !== false;
    }

    /** @param list<string> $keys */
    public function deleteItems(array $keys): bool
    {
        foreach ($this->groupByBucket($keys) as $group) {
            if ($this->call('del', array_map($this->mapData(...), $group)) === false) {
                return false;
            }
        }

        return true;
    }

    public function getClient(): object
    {
        return $this->cluster;
    }

    public function getItem(string $key): CacheItem
    {
        $bucket = $this->bucket($key);
        $values = $this->call('mget', [$this->generationKey($bucket), $this->mapData($key)]);
        $values = is_array($values) ? array_values($values) : [];
        $generation = $this->namespaceGeneration($bucket, $values[0] ?? null);
        $blob = $values[1] ?? null;
        $record = is_string($blob) ? $this->decodeRecordFromBlob($blob) : null;
        if ($record !== null && $record->namespaceGeneration === $generation) {
            return $this->genericItemFromRecord($key, $record);
        }
        if (is_string($blob)) {
            $this->call('del', $this->mapData($key));
        }

        return $this->genericMiss($key);
    }

    /**
     * @param list<string> $tags
     * @return array<string, string>
     */
    #[\Override]
    public function getTagGenerations(array $tags): array
    {
        $generations = [];
        foreach ($this->groupByBucket($tags) as $group) {
            $values = $this->call('mget', array_map($this->mapTag(...), $group));
            $values = is_array($values) ? array_values($values) : [];
            $initialize = [];
            foreach ($group as $index => $tag) {
                $generation = self::normalizeGeneration($values[$index] ?? null);
                if ($generation === null) {
                    $generation = self::newGeneration();
                    $initialize[$this->mapTag($tag)] = $generation;
                }
                $generations[$tag] = $generation;
            }
            if ($initialize !== [] && !$this->call('mset', $initialize)) {
                throw new RuntimeException('Unable to initialize Redis Cluster tag generations.');
            }
        }

        return $generations;
    }

    public function hasItem(string $key): bool
    {
        return $this->getItem($key)->isHit();
    }

    /**
     * @param list<string> $keys
     * @return array<string, CacheItem>
     */
    public function multiFetch(array $keys): array
    {
        $items = [];
        $stale = [];
        foreach ($this->groupByBucket($keys) as $bucket => $group) {
            $bucketResult = $this->fetchBucket($bucket, $group);
            $items += $bucketResult['items'];
            $stale = [...$stale, ...$bucketResult['stale']];
        }
        $this->deleteItems($stale);

        $ordered = [];
        foreach ($keys as $key) {
            $ordered[$key] = $items[$key] ?? $this->genericMiss($key);
        }

        return $ordered;
    }

    /** @param list<string> $tags */
    #[\Override]
    public function rotateTagGenerations(array $tags): bool
    {
        foreach ($this->groupByBucket($tags) as $group) {
            $generations = [];
            foreach ($group as $tag) {
                $generations[$this->mapTag($tag)] = self::newGeneration();
            }
            if (!$this->call('mset', $generations)) {
                return false;
            }
        }

        return true;
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
        $bucket = $this->bucket($item->getKey());
        $generation = $this->namespaceGeneration($bucket);
        $blob = $this->encodeItem($item, $expiration['expiresAt'], $generation);

        return (bool) ($expiration['ttl'] === null
            ? $this->call('set', $this->mapData($item->getKey()), $blob)
            : $this->call('setex', $this->mapData($item->getKey()), max(1, $expiration['ttl']), $blob));
    }

    /** @param array<string, CacheItemInterface> $items */
    public function saveItems(array $items): bool
    {
        foreach ($items as $item) {
            if (!$this->supportsItem($item)) {
                return false;
            }
        }
        foreach ($this->groupItemsByBucket($items) as $bucket => $group) {
            if (!$this->saveBucket($bucket, $group)) {
                return false;
            }
        }

        return true;
    }

    private function bucket(string $key): int
    {
        return hexdec(substr(hash('xxh3', $key), 0, 8)) % self::BUCKET_COUNT;
    }

    private function call(string $method, mixed ...$arguments): mixed
    {
        return $this->cluster->{$method}(...$arguments);
    }

    private function callObject(object $target, string $method, mixed ...$arguments): mixed
    {
        $callable = [$target, $method];
        if (!is_callable($callable)) {
            return false;
        }

        return $callable(...$arguments);
    }

    /**
     * @param list<string> $keys
     * @return array{items: array<string, CacheItem>, stale: list<string>}
     */
    private function fetchBucket(int $bucket, array $keys): array
    {
        $physical = [$this->generationKey($bucket), ...array_map($this->mapData(...), $keys)];
        $values = $this->call('mget', $physical);
        $values = is_array($values) ? array_values($values) : [];
        $generation = $this->namespaceGeneration($bucket, $values[0] ?? null);
        $items = [];
        $stale = [];
        foreach ($keys as $index => $key) {
            $blob = $values[$index + 1] ?? null;
            $record = is_string($blob) ? $this->decodeRecordFromBlob($blob) : null;
            if ($record !== null && $record->namespaceGeneration === $generation) {
                $items[$key] = $this->genericItemFromRecord($key, $record);

                continue;
            }
            $items[$key] = $this->genericMiss($key);
            if (is_string($blob)) {
                $stale[] = $key;
            }
        }

        return ['items' => $items, 'stale' => $stale];
    }

    private function generationKey(int $bucket): string
    {
        return $this->prefix($bucket) . ':m:generation';
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

    /**
     * @param array<string, CacheItemInterface> $items
     * @return array<int, list<CacheItemInterface>>
     */
    private function groupItemsByBucket(array $items): array
    {
        $groups = [];
        foreach ($items as $item) {
            $groups[$this->bucket($item->getKey())][] = $item;
        }

        return $groups;
    }

    private function mapData(string $key): string
    {
        return $this->prefix($this->bucket($key)) . ':d:' . $key;
    }

    private function mapTag(string $tag): string
    {
        return $this->prefix($this->bucket($tag)) . ':m:tag:' . $tag;
    }

    private function namespaceGeneration(int $bucket, mixed $value = null): string
    {
        $value ??= $this->call('get', $this->generationKey($bucket));
        $generation = self::normalizeGeneration($value);
        if ($generation !== null) {
            return $generation;
        }

        $candidate = self::newGeneration();
        $stored = $this->call('set', $this->generationKey($bucket), $candidate, ['nx']);
        $current = $stored ? $candidate : $this->call('get', $this->generationKey($bucket));
        $generation = self::normalizeGeneration($current);
        if ($generation === null) {
            $generation = self::newGeneration();
            if (!$this->call('set', $this->generationKey($bucket), $generation)) {
                throw new RuntimeException('Unable to initialize Redis Cluster namespace generation.');
            }
        }

        return $generation;
    }

    private function prefix(int $bucket): string
    {
        return $this->namespace . ':{' . $this->namespace . '-' . $bucket . '}';
    }

    private function recordTagsAreCurrent(CacheRecord $record): bool
    {
        if ($record->tags === []) {
            return true;
        }

        $current = $this->getTagGenerations(array_keys($record->tags));
        foreach ($record->tags as $tag => $generation) {
            if (($current[$tag] ?? null) !== $generation) {
                return false;
            }
        }

        return true;
    }

    /** @param list<CacheItemInterface> $items */
    private function saveBucket(int $bucket, array $items): bool
    {
        $generation = $this->namespaceGeneration($bucket);
        $plain = [];
        $expiring = [];
        foreach ($items as $item) {
            $expiration = CachePayloadCodec::expirationFromItem($item);
            if ($expiration['ttl'] !== null && $expiration['ttl'] <= 0) {
                $this->call('del', $this->mapData($item->getKey()));

                continue;
            }
            $blob = $this->encodeItem($item, $expiration['expiresAt'], $generation);
            if ($expiration['ttl'] === null) {
                $plain[$this->mapData($item->getKey())] = $blob;

                continue;
            }
            $expiring[] = [$this->mapData($item->getKey()), $expiration['ttl'], $blob];
        }

        if ($plain !== [] && !$this->call('mset', $plain)) {
            return false;
        }
        if ($expiring === []) {
            return true;
        }

        return $this->saveExpiring($expiring);
    }

    /** @param list<array{0:string, 1:int, 2:string}> $records */
    private function saveExpiring(array $records): bool
    {
        $pipeline = $this->call('multi', self::PIPELINE_MODE);
        if (!is_object($pipeline)) {
            return false;
        }
        foreach ($records as [$key, $ttl, $blob]) {
            $this->callObject($pipeline, 'setex', $key, $ttl, $blob);
        }
        $results = $this->callObject($pipeline, 'exec');

        return is_array($results)
            && count($results) === count($records)
            && AdapterValueNormalizer::allTrue($results);
    }
}
