<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Counter;

interface AtomicCounterStoreInterface
{
    public function decrement(string $key, int $by = 1, ?int $ttlSeconds = null): AtomicCounterValue;

    public function delete(string $key): bool;

    public function get(string $key): ?int;

    public function increment(string $key, int $by = 1, ?int $ttlSeconds = null): AtomicCounterValue;
}
