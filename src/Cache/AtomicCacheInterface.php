<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cache;

use DateInterval;
use DateTimeInterface;

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
     * Atomically replace one existing live value when it strictly matches the expected value.
     *
     * Absence is distinct from a cached null value. Use setIfAbsent() when
     * absence itself is the condition. A non-positive TTL is a no-op and
     * returns false.
     */
    public function compareAndSet(
        string $key,
        mixed $expected,
        mixed $replacement,
        null|int|DateInterval|DateTimeInterface $ttl = null,
    ): bool;

    /**
     * Atomically return and consume the current live value.
     *
     * The default is returned when the key is absent/expired or when a
     * fail-open backend cannot complete the operation.
     */
    public function getAndDelete(string $key, mixed $default = null): mixed;

    /**
     * Store a value only when no live value currently exists for the key.
     *
     * A false result is a normal conditional miss, not necessarily a backend
     * failure. A non-positive TTL is a no-op and returns false.
     */
    public function setIfAbsent(
        string $key,
        mixed $value,
        null|int|DateInterval|DateTimeInterface $ttl = null,
    ): bool;
}
