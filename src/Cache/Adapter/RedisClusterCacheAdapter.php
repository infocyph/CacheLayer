<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cache\Adapter;

use Infocyph\CacheLayer\Cache\Item\GenericCacheItem;
use Psr\Cache\CacheItemInterface;
use RuntimeException;

final class RedisClusterCacheAdapter extends AbstractCacheAdapter
{
    private readonly object $cluster;
    private readonly string $ns;

    /**
     * @param array<int, string> $seeds
     */
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

            $client = new \RedisCluster(
                null,
                $seeds,
                $timeout,
                $readTimeout,
                $persistent,
            );
        }

        $this->ns = sanitize_cache_ns($namespace);
        $this->assertClientShape($client);
        $this->cluster = $client;
    }

    public function clear(): bool
    {
        $keys = $this->cluster->sMembers($this->indexKey());
        if (is_array($keys) && $keys !== []) {
            foreach ($keys as $key) {
                $this->cluster->del((string) $key);
            }
        }
        $this->cluster->del($this->indexKey());
        $this->deferred = [];
        return true;
    }

    public function count(): int
    {
        return (int) $this->cluster->sCard($this->indexKey());
    }

    public function deleteItem(string $key): bool
    {
        $mapped = $this->map($key);
        $this->cluster->sRem($this->indexKey(), $mapped);
        return $this->cluster->del($mapped) !== false;
    }

    public function deleteItems(array $keys): bool
    {
        foreach ($keys as $key) {
            $this->deleteItem((string) $key);
        }

        return true;
    }

    public function getClient(): object
    {
        return $this->cluster;
    }

    public function getItem(string $key): GenericCacheItem
    {
        $mapped = $this->map($key);
        $raw = $this->cluster->get($mapped);
        if (!is_string($raw)) {
            return new GenericCacheItem($this, $key);
        }

        $record = CachePayloadCodec::decode($raw);
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
        return $this->cluster->exists($this->map($key)) > 0;
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

        $mapped = $this->map($item->getKey());
        $blob = CachePayloadCodec::encode($item->get(), $expires['expiresAt']);

        $ok = $expires['ttl'] === null
            ? $this->cluster->set($mapped, $blob)
            : $this->cluster->setex($mapped, max(1, $expires['ttl']), $blob);

        if ($ok) {
            $this->cluster->sAdd($this->indexKey(), $mapped);
        }

        return (bool) $ok;
    }

    protected function supportsItem(CacheItemInterface $item): bool
    {
        return $item instanceof GenericCacheItem;
    }

    private function assertClientShape(object $client): void
    {
        foreach (['sMembers', 'del', 'sCard', 'get', 'exists', 'set', 'setex', 'sAdd', 'sRem'] as $method) {
            if (!method_exists($client, $method)) {
                throw new RuntimeException(
                    sprintf('RedisClusterCacheAdapter client must expose `%s()`.', $method),
                );
            }
        }
    }

    private function indexKey(): string
    {
        return $this->ns . ':__keys';
    }

    private function map(string $key): string
    {
        return $this->ns . ':' . $key;
    }
}
