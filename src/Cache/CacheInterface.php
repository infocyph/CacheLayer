<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cache;

use ArrayAccess;
use Infocyph\CacheLayer\Cache\Lock\LockProviderInterface;
use Infocyph\CacheLayer\Cache\Metrics\CacheMetricsCollectorInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\SimpleCache\CacheInterface as SimpleCacheInterface;

/** @extends ArrayAccess<string, mixed> */
interface CacheInterface extends ArrayAccess, CacheItemPoolInterface, SimpleCacheInterface
{
    /** @return array<string, array<string, int>> */
    public function exportMetrics(): array;

    public function invalidateTag(string $tag): bool;

    /** @param list<string> $tags */
    public function invalidateTags(array $tags): bool;

    /** @param list<string> $tags */
    public function remember(string $key, callable $resolver, mixed $ttl = null, array $tags = []): mixed;

    public function setLockProvider(LockProviderInterface $lockProvider): self;

    public function setMetricsCollector(CacheMetricsCollectorInterface $metrics): self;

    public function setMetricsExportHook(?callable $hook): self;

    /** @param list<string> $tags */
    public function setTagged(string $key, mixed $value, array $tags, mixed $ttl = null): bool;

    public function useMemcachedLock(?\Memcached $client = null, string $prefix = 'cachelayer:lock:'): self;

    public function useRedisLock(?\Redis $client = null, string $prefix = 'cachelayer:lock:'): self;

    public function useValkeyLock(?\Redis $client = null, string $prefix = 'cachelayer:lock:'): self;
}
