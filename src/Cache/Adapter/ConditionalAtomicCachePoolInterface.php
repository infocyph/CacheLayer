<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cache\Adapter;

/** @internal */
interface ConditionalAtomicCachePoolInterface extends AtomicCachePoolInterface
{
    public function supportsAtomicCache(): bool;
}
