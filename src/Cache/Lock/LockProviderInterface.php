<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cache\Lock;

interface LockProviderInterface
{
    public function acquire(string $key, float $waitSeconds): ?LockHandle;

    public function release(?LockHandle $handle): void;
}
