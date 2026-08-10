<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cache\Adapter;

use Infocyph\CacheLayer\Cache\Item\CacheItem;
use Infocyph\CacheLayer\Exceptions\CacheInvalidArgumentException;
use Infocyph\CacheLayer\Support\RedisConnection;
use InvalidArgumentException;
use Psr\Cache\CacheItemInterface;
use RuntimeException;

/**
 * Redis-based cache adapter implementation.
 *
 * This adapter uses Redis to provide high-performance distributed caching.
 * It supports both standalone Redis instances and Redis clusters,
 * making it suitable for production environments with multiple web servers.
 *
 * This This adapter requires the phpredis extension to be installed.
     * @param string $namespace A namespace prefix to avoid key collisions.
     * @param string $dsn The Redis connection DSN (e.g., 'redis://127.0.0.1:6379').
     * @param \Redis|null $client Optional pre-configured Redis client instance.
 */
class RedisCacheAdapter extends AbstractCacheAdapter
{
    private readonly string $ns;

    private readonly \Redis $redis;

    /**
     * Creates a new Redis cache adapter.
     *
     *
     * @throws RuntimeException If the phpredis extension is not loaded.
     * @param string $namespace A namespace prefix to avoid key collisions.
     * @param string $dsn The Redis connection DSN (e.g., 'redis://127.0.0.1:6379').
     * @param \Redis|null $client Optional pre-configured Redis client instance.
     */
    public function __construct(
        string $namespace = 'default',
        string $dsn = 'redis://127.0.0.1:6379',
        ?\Redis $client = null,
    ) {
        if (!class_exists(\Redis::class)) {
            throw new RuntimeException('phpredis extension not loaded');
        }

        $this->ns = sanitize_cache_ns($namespace);
        $this->redis = $client ?? $this->connect($dsn);
    }

    public function clear(): bool
    {
        $cursor = null;
        do {
            $keys = $this->redis->scan($cursor, $this->ns . ':*', 1000);
            if ($keys) {
                $this->redis->del($keys);
            }
        } while ($cursor);
        $this->deferred = [];

        return true;
    }

    public function count(): int
    {
        $iter = null;
        $count = 0;
        while ($keys = $this->redis->scan($iter, $this->ns . ':d:*', 1000)) {
            $count += count($keys);
        }

        return $count;
    }

    public function deleteItem(string $key): bool
    {
        return $this->redis->del($this->map($key)) !== false;
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

        $full = array_map($this->map(...), $keys);

        return $this->redis->del($full) !== false;
    }

    public function getClient(): \Redis
    {
        return $this->redis;
    }

    public function getItem(string $key): CacheItem
    {
        $raw = $this->redis->get($this->map($key));
        if (is_string($raw)) {
            $record = $this->decodeRecordFromBlob($raw);
            if ($record !== null) {
                return $this->genericItemFromRecord($key, $record);
            }
            $this->redis->del($this->map($key));
        }

        return new CacheItem($this, $key);
    }

    /** @param list<string> $tags */
    #[\Override]
    public function getTagVersions(array $tags): array
    {
        if ($tags === []) {
            return [];
        }

        $values = $this->redis->mget(array_map($this->mapTag(...), $tags));
        $values = is_array($values) ? array_values($values) : [];
        $versions = [];
        foreach ($tags as $index => $tag) {
            $value = $values[$index] ?? null;
            $versions[$tag] = is_numeric($value) ? max(0, (int) $value) : 0;
        }

        return $versions;
    }

    public function hasItem(string $key): bool
    {
        return $this->redis->exists($this->map($key)) === 1;
    }

    /** @param list<string> $tags */
    #[\Override]
    public function incrementTagVersions(array $tags): bool
    {
        if ($tags === []) {
            return true;
        }
        if (count($tags) === 1) {
            return $this->redis->incr($this->mapTag($tags[0])) !== false;
        }

        $pipeline = $this->redis->multi(\Redis::PIPELINE);
        foreach ($tags as $tag) {
            $pipeline->incr($this->mapTag($tag));
        }

        return $pipeline->exec() !== false;
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

        $prefixed = array_map($this->map(...), $keys);
        $rawVals = $this->redis->mget($prefixed);
        if (!is_array($rawVals)) {
            $rawVals = [];
        }
        $rawVals = array_values($rawVals);

        $items = [];
        $stale = [];
        foreach ($keys as $idx => $k) {
            $v = $rawVals[$idx] ?? null;
            if ($v !== null && $v !== false) {
                if (!is_string($v)) {
                    $items[$k] = new CacheItem($this, $k);

                    continue;
                }

                $record = $this->decodeRecordFromBlob($v);
                if ($record !== null) {
                    $items[$k] = $this->genericItemFromRecord($k, $record);

                    continue;
                }
                $stale[] = $this->map($k);
            }
            $items[$k] = new CacheItem($this, $k);
        }

        if ($stale !== []) {
            $this->redis->del($stale);
        }

        return $items;
    }

    public function save(CacheItemInterface $item): bool
    {
        if (!$this->supportsItem($item)) {
            throw new CacheInvalidArgumentException('The cache item belongs to another pool.');
        }

        $expires = CachePayloadCodec::expirationFromItem($item);
        $ttl = $expires['ttl'];
        if ($ttl !== null && $ttl <= 0) {
            $this->redis->del($this->map($item->getKey()));

            return true;
        }

        $blob = $this->encodeItem($item, $expires['expiresAt']);

        return $ttl === null
            ? $this->redis->set($this->map($item->getKey()), $blob)
            : $this->redis->setex($this->map($item->getKey()), max(1, $ttl), $blob);
    }

    /** @param array<string, CacheItemInterface> $items */
    public function saveItems(array $items): bool
    {
        if (!$this->supportsItems($items)) {
            return false;
        }

        $plain = [];
        $expiring = [];
        $expired = [];
        foreach ($items as $item) {
            $expiration = CachePayloadCodec::expirationFromItem($item);
            if ($expiration['ttl'] !== null && $expiration['ttl'] <= 0) {
                $expired[] = $this->map($item->getKey());

                continue;
            }
            $blob = $this->encodeItem($item, $expiration['expiresAt']);
            if ($expiration['ttl'] === null) {
                $plain[$this->map($item->getKey())] = $blob;
            } else {
                $expiring[] = [$this->map($item->getKey()), max(1, $expiration['ttl']), $blob];
            }
        }

        if ($expired !== []) {
            $this->redis->del($expired);
        }

        $ok = $plain === [] || $this->redis->mset($plain);
        if ($expiring === []) {
            return $ok;
        }
        $pipeline = $this->redis->multi(\Redis::PIPELINE);
        foreach ($expiring as [$key, $ttl, $blob]) {
            $pipeline->setex($key, $ttl, $blob);
        }

        return $pipeline->exec() !== false && $ok;
    }

    private function connect(string $dsn): \Redis
    {
        try {
            return RedisConnection::connect($dsn);
        } catch (InvalidArgumentException $exception) {
            throw new RuntimeException("Invalid Redis DSN: $dsn", 0, $exception);
        }
    }

    private function map(string $key): string
    {
        return $this->ns . ':d:' . $key;
    }

    private function mapTag(string $tag): string
    {
        return $this->ns . ':m:tag:' . $tag;
    }
}
