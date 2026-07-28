<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cache\Lock;

interface LockProviderInterface
{
    public function acquire(string $key, float $waitSeconds, float $leaseSeconds = 30.0): ?LockHandle;

    public function refresh(?LockHandle $handle, float $leaseSeconds): bool;

    public function release(?LockHandle $handle): void;
}
