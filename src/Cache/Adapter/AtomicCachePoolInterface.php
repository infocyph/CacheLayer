<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cache\Adapter;

use Psr\Cache\CacheItemInterface;

/**
 * Internal backend contract for atomic cache operations.
 *
 * @internal
 */
interface AtomicCachePoolInterface extends InternalCachePoolInterface
{
    public function atomicSetIfAbsent(CacheItemInterface $item): bool;

    public function atomicGetAndDelete(string $key): CacheItemInterface;

    public function atomicCompareAndSet(
        string $key,
        mixed $expected,
        CacheItemInterface $replacement,
    ): bool;
}
