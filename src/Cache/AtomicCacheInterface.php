<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cache;

/**
 * Optional cache capability for linearizable conditional/consuming operations.
 *
 * Implementations must not emulate these methods with ordinary read-then-write
 * cache calls unless the lower layer provides equivalent atomic semantics.
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

    /**
     * Atomically replace an existing live value when it strictly matches the
     * expected value.
     *
     * Comparison uses strict PHP value equality (===). A non-positive TTL
     * performs an atomic compare-and-delete when the expected value matches.
     */
    public function compareAndSet(
        string $key,
        mixed $expected,
        mixed $replacement,
        mixed $ttl = null,
    ): bool;
}
