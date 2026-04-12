<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cache;

use ArrayAccess;
use Countable;
use Infocyph\CacheLayer\Cache\Lock\LockProviderInterface;
use Infocyph\CacheLayer\Cache\Metrics\CacheMetricsCollectorInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\SimpleCache\CacheInterface as SimpleCacheInterface;

/**
 * Unified cache interface combining PSR-6, PSR-16, and additional functionality.
 *
 * This interface extends multiple cache standards to provide a comprehensive
 * caching solution:
 * - PSR-6 CacheItemPoolInterface: Advanced caching with items and metadata
 * - PSR-16 SimpleCacheInterface: Simplified caching operations
 * - ArrayAccess: Array-like access to cache entries
 * - Countable: Count cache entries
 *
 * Implementations of this interface provide a unified API for both simple
 * and advanced caching use cases, supporting features like tagged cache
 * invalidation, cache stampede protection, and multiple storage adapters.
 */
interface CacheInterface extends CacheItemPoolInterface, SimpleCacheInterface, ArrayAccess, Countable
{
    public function clearCache(): bool;

    public function configurePayloadCompression(?int $thresholdBytes = null, int $level = 6): self;

    /**
     * Returns metrics grouped by readable adapter name (for example ``file``,
     * ``pdo``, ``redis``) and metric name.
     *
     * @return array<string, array<string, int>>
     */
    public function exportMetrics(): array;

    public function invalidateTag(string $tag): bool;

    /**
     * @param array<int, string> $tags
     */
    public function invalidateTags(array $tags): bool;

    /**
     * @param array<int, string> $tags
     */
    public function remember(string $key, callable $resolver, mixed $ttl = null, array $tags = []): mixed;

    public function setLockProvider(LockProviderInterface $lockProvider): self;

    public function setMetricsCollector(CacheMetricsCollectorInterface $metrics): self;

    public function setMetricsExportHook(?callable $hook): self;

    /**
     * @param array<int, string> $tags
     */
    public function setTagged(string $key, mixed $value, array $tags, mixed $ttl = null): bool;

    public function useMemcachedLock(?\Memcached $client = null, string $prefix = 'cachelayer:lock:'): self;

    public function useRedisLock(?\Redis $client = null, string $prefix = 'cachelayer:lock:'): self;
}
