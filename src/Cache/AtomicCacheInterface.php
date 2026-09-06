<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cache;

/**
 * Optional cache capability for atomic conditional/consuming operations.
 *
 * Implementations must provide atomic semantics inside the backend's
 * documented consistency domain. These methods must not be emulated with
 * ordinary read-then-write cache calls.
 */
interface AtomicCacheInterface
{
    /**
     * Store a value only when no live value currently exists for the key.
     *
     * A false result is a normal conditional miss, not necessarily a backend
     * failure. A non-positive TTL is a no-op and returns false.
     */
    public function setIfAbsent(string $key, mixed $value, mixed $ttl = null): bool;

    /**
     * Atomically return and consume the current live value.
     *
     * The default is returned when the key is absent/expired or when a
     * fail-open backend cannot complete the operation.
     */
    public function getAndDelete(string $key, mixed $default = null): mixed;
}
