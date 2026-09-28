<?php

declare(strict_types=1);

namespace CacheLayerRelease;

use Cache\IntegrationTests\CachePoolTest;
use Cache\IntegrationTests\SimpleCacheTest;
use Infocyph\CacheLayer\Cache\Cache;
use Psr\Cache\CacheItemPoolInterface;
use Psr\SimpleCache\CacheInterface;

final class Psr6MemoryIntegrationTest extends CachePoolTest
{
    public function createCachePool(): CacheItemPoolInterface
    {
        return Cache::memory('psr6-' . bin2hex(random_bytes(6)));
    }
}

final class Psr16MemoryIntegrationTest extends SimpleCacheTest
{
    public function createSimpleCache(): CacheInterface
    {
        return Cache::memory('psr16-' . bin2hex(random_bytes(6)));
    }
}
