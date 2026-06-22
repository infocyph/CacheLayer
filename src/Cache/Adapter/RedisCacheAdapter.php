<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cache\Adapter;

use Infocyph\CacheLayer\Cache\Item\RedisCacheItem;
use Infocyph\CacheLayer\Exceptions\CacheInvalidArgumentException;
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
        while ($keys = $this->redis->scan($iter, $this->ns . ':*', 1000)) {
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

    public function getItem(string $key): RedisCacheItem
    {
        $raw = $this->redis->get($this->map($key));
        if (is_string($raw)) {
            $record = CachePayloadCodec::decode($raw);
            if ($record !== null && !CachePayloadCodec::isExpired($record['expires'])) {
                return new RedisCacheItem(
                    $this,
                    $key,
                    $record['value'],
                    true,
                    CachePayloadCodec::toDateTime($record['expires']),
                );
            }
            $this->redis->del($this->map($key));
        }

        return new RedisCacheItem($this, $key);
    }

    public function hasItem(string $key): bool
    {
        return $this->redis->exists($this->map($key)) === 1;
    }

    /**
     * @param array $keys The keys argument.
     * @phpstan-param list<string> $keys
     * @phpstan-return array<string, RedisCacheItem>
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
                    $items[$k] = new RedisCacheItem($this, $k);

                    continue;
                }

                $record = CachePayloadCodec::decode($v);
                if ($record !== null && !CachePayloadCodec::isExpired($record['expires'])) {
                    $items[$k] = new RedisCacheItem(
                        $this,
                        $k,
                        $record['value'],
                        true,
                        CachePayloadCodec::toDateTime($record['expires']),
                    );

                    continue;
                }
                $stale[] = $this->map($k);
            }
            $items[$k] = new RedisCacheItem($this, $k);
        }

        if ($stale !== []) {
            $this->redis->del($stale);
        }

        return $items;
    }

    public function save(CacheItemInterface $item): bool
    {
        if (!$this->supportsItem($item)) {
            throw new CacheInvalidArgumentException('RedisCacheAdapter expects RedisCacheItem');
        }

        $expires = CachePayloadCodec::expirationFromItem($item);
        $ttl = $expires['ttl'];
        if ($ttl === 0) {
            $this->redis->del($this->map($item->getKey()));

            return true;
        }

        $blob = CachePayloadCodec::encode($item->get(), $expires['expiresAt']);

        return $ttl === null
            ? $this->redis->set($this->map($item->getKey()), $blob)
            : $this->redis->setex($this->map($item->getKey()), max(1, $ttl), $blob);
    }

    protected function supportsItem(CacheItemInterface $item): bool
    {
        return $item instanceof RedisCacheItem;
    }

    private function connect(string $dsn): \Redis
    {
        $r = new \Redis();
        $parts = parse_url($dsn);
        if (!$parts) {
            throw new RuntimeException("Invalid Redis DSN: $dsn");
        }
        $host = is_string($parts['host'] ?? null) ? $parts['host'] : '127.0.0.1';
        $port = is_int($parts['port'] ?? null) ? $parts['port'] : 6379;
        $r->connect($host, $port);
        if (is_string($parts['pass'] ?? null) && $parts['pass'] !== '') {
            $r->auth($parts['pass']);
        }
        if (is_string($parts['path'] ?? null) && $parts['path'] !== '/') {
            $db = (int) ltrim($parts['path'], '/');
            $r->select($db);
        }

        return $r;
    }

    private function map(string $key): string
    {
        return $this->ns . ':' . $key;
    }
}
