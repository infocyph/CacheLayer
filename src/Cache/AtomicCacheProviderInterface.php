<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cache;

/**
 * Exposes the optional atomic cache capability without making every cache
 * backend claim operations it cannot guarantee.
 */
interface AtomicCacheProviderInterface
{
    public function atomic(): ?AtomicCacheInterface;
}
