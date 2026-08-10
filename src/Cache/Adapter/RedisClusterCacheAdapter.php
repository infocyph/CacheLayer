<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cache\Adapter;

use Infocyph\CacheLayer\Cache\Item\CacheItem;
use Psr\Cache\CacheItemInterface;
use RuntimeException;

final class RedisClusterCacheAdapter extends AbstractCacheAdapter
{
    private const int BUCKET_COUNT = 128;

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
        foreach (['del', 'exists', 'get', 'incr', 'mget', 'mset', 'set', 'setex'] as $method) {
            if (!method_exists($client, $method)) {
                throw new RuntimeException("Redis Cluster client must expose {$method}().");
            }
        }
        $this->namespace = sanitize_cache_ns($namespace);
        $this->cluster = $client;
    }

    public function clear(): bool
    {
        for ($bucket = 0; $bucket < self::BUCKET_COUNT; $bucket++) {
            if ($this->call('incr', $this->epochKey($bucket)) === false) {
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
        $values = $this->call('mget', [$this->epochKey($bucket), $this->mapData($key)]);
        $values = is_array($values) ? array_values($values) : [];
        $epoch = $this->normalizeVersion($values[0] ?? null);
        $blob = $values[1] ?? null;
        $record = is_string($blob) ? $this->decodeRecordFromBlob($blob) : null;
        if ($record !== null && ($record->namespaceEpoch ?? 0) === $epoch) {
            return $this->genericItemFromRecord($key, $record);
        }
        if (is_string($blob)) {
            $this->call('del', $this->mapData($key));
        }

        return $this->genericMiss($key);
    }

    /**
     * @param list<string> $tags
     * @return array<string, int>
     */
    #[\Override]
    public function getTagVersions(array $tags): array
    {
        $versions = [];
        foreach ($this->groupByBucket($tags) as $group) {
            $values = $this->call('mget', array_map($this->mapTag(...), $group));
            $values = is_array($values) ? array_values($values) : [];
            foreach ($group as $index => $tag) {
                $versions[$tag] = $this->normalizeVersion($values[$index] ?? null);
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
            if ($this->call('incr', $this->mapTag($tag)) === false) {
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
        $epoch = $this->normalizeVersion($this->call('get', $this->epochKey($bucket)));
        $blob = $this->encodeItem($item, $expiration['expiresAt'], $epoch);

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

    private function epochKey(int $bucket): string
    {
        return $this->prefix($bucket) . ':m:epoch';
    }

    /**
     * @param list<string> $keys
     * @return array{items: array<string, CacheItem>, stale: list<string>}
     */
    private function fetchBucket(int $bucket, array $keys): array
    {
        $physical = [$this->epochKey($bucket), ...array_map($this->mapData(...), $keys)];
        $values = $this->call('mget', $physical);
        $values = is_array($values) ? array_values($values) : [];
        $epoch = $this->normalizeVersion($values[0] ?? null);
        $items = [];
        $stale = [];
        foreach ($keys as $index => $key) {
            $blob = $values[$index + 1] ?? null;
            $record = is_string($blob) ? $this->decodeRecordFromBlob($blob) : null;
            if ($record !== null && ($record->namespaceEpoch ?? 0) === $epoch) {
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

    private function normalizeVersion(mixed $value): int
    {
        return is_numeric($value) ? max(0, (int) $value) : 0;
    }

    private function prefix(int $bucket): string
    {
        return $this->namespace . ':{' . $this->namespace . '-' . $bucket . '}';
    }

    /** @param list<CacheItemInterface> $items */
    private function saveBucket(int $bucket, array $items): bool
    {
        $epoch = $this->normalizeVersion($this->call('get', $this->epochKey($bucket)));
        $plain = [];
        foreach ($items as $item) {
            $expiration = CachePayloadCodec::expirationFromItem($item);
            if ($expiration['ttl'] !== null && $expiration['ttl'] <= 0) {
                $this->call('del', $this->mapData($item->getKey()));

                continue;
            }
            $blob = $this->encodeItem($item, $expiration['expiresAt'], $epoch);
            if ($expiration['ttl'] === null) {
                $plain[$this->mapData($item->getKey())] = $blob;

                continue;
            }
            if (!$this->call('setex', $this->mapData($item->getKey()), $expiration['ttl'], $blob)) {
                return false;
            }
        }

        return $plain === [] || (bool) $this->call('mset', $plain);
    }
}
