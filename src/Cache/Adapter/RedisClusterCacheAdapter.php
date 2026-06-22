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
     * @param string $namespace The namespace argument.
     * @param array $seeds The seeds argument.
     * @param float $timeout The timeout argument.
     * @param float $readTimeout The read timeout argument.
     * @param bool $persistent The persistent argument.
     * @param object|null $client The client argument.
     * @phpstan-param array<int, string> $seeds
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
        $keys = $this->call('sMembers', $this->indexKey());
        if (is_array($keys) && $keys !== []) {
            foreach ($keys as $key) {
                if (is_string($key)) {
                    $this->call('del', $key);
                }
            }
        }
        $this->call('del', $this->indexKey());
        $this->deferred = [];

        return true;
    }

    public function count(): int
    {
        $count = $this->call('sCard', $this->indexKey());

        return is_int($count) ? max(0, $count) : 0;
    }

    public function deleteItem(string $key): bool
    {
        $mapped = $this->map($key);
        $this->call('sRem', $this->indexKey(), $mapped);

        return $this->call('del', $mapped) !== false;
    }

    /**
     * @param array $keys The keys argument.
     * @phpstan-param list<string> $keys
     */
    public function deleteItems(array $keys): bool
    {
        foreach ($keys as $key) {
            $this->deleteItem($key);
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
        $raw = $this->call('get', $mapped);

        return $this->genericFromBlob($key, is_string($raw) ? $raw : null);
    }

    public function hasItem(string $key): bool
    {
        $exists = $this->call('exists', $this->map($key));

        return is_int($exists) && $exists > 0;
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
        return $this->saveEncoded($item, function (CacheItemInterface $saveItem, array $expires): bool {
            $mapped = $this->map($saveItem->getKey());
            $blob = CachePayloadCodec::encode($saveItem->get(), $expires['expiresAt']);

            $ok = $expires['ttl'] === null
                ? $this->call('set', $mapped, $blob)
                : $this->call('setex', $mapped, max(1, $expires['ttl']), $blob);

            if ($ok) {
                $this->call('sAdd', $this->indexKey(), $mapped);
            }

            return (bool) $ok;
        });
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

    private function call(string $method, mixed ...$arguments): mixed
    {
        return $this->cluster->{$method}(...$arguments);
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
