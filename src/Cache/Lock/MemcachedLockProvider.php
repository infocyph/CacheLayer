<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cache\Lock;

use Infocyph\CacheLayer\Cache\Adapter\MemcachedExpiration;
use Infocyph\CacheLayer\Support\MemcachedValueGuard;
use RuntimeException;

final readonly class MemcachedLockProvider implements LockProviderInterface
{
    use GeneratesLockTokens;
    use PollingLockProviderHelpers;

    private const string RELEASED_PREFIX = 'released:';

    private int $retrySleepMicros;

    /**
     * @throws RuntimeException
     */
    public function __construct(
        private \Memcached $memcached,
        private string $prefix = 'cachelayer:lock:',
        int $retrySleepMicros = 50_000,
    ) {
        if (!class_exists(\Memcached::class)) {
            throw new RuntimeException('Memcached extension not loaded');
        }
        $this->retrySleepMicros = self::normalizeRetrySleepMicros($retrySleepMicros);
    }

    /**
     * @throws \Infocyph\CacheLayer\Exceptions\CacheInvalidArgumentException
     * @throws \InvalidArgumentException
     */
    #[\Override]
    public function acquire(string $key, float $waitSeconds, float $leaseSeconds = 30.0): ?LockHandle
    {
        $ttlSeconds = MemcachedExpiration::fromRelative(self::leaseSeconds($leaseSeconds));

        return $this->acquireWithRetry(
            $this->prefix,
            $key,
            $waitSeconds,
            $leaseSeconds,
            fn(string $lockKey, string $token): bool => $this->tryAcquire($lockKey, $token, $ttlSeconds),
        );
    }

    /**
     * @throws \Infocyph\CacheLayer\Exceptions\CacheInvalidArgumentException
     * @throws \InvalidArgumentException
     */
    #[\Override]
    public function refresh(?LockHandle $handle, float $leaseSeconds): bool
    {
        if (!$handle instanceof LockHandle) {
            return false;
        }

        $ttlSeconds = MemcachedExpiration::fromRelative(self::leaseSeconds($leaseSeconds));
        $values = $this->memcached->getMulti([$handle->key], \Memcached::GET_EXTENDED);
        if (!is_array($values)) {
            return false;
        }

        $entry = $values[$handle->key] ?? null;
        if (!is_array($entry) || ($entry['value'] ?? null) !== $handle->token) {
            return false;
        }

        $casToken = MemcachedValueGuard::casToken($entry['cas'] ?? null);
        if ($casToken === null) {
            return false;
        }

        return $this->memcached->cas($casToken, $handle->key, $handle->token, $ttlSeconds);
    }

    #[\Override]
    public function release(?LockHandle $handle): void
    {
        $this->releaseWithGuard($handle, function (LockHandle $lock): void {
            $values = $this->memcached->getMulti([$lock->key], \Memcached::GET_EXTENDED);
            $entry = is_array($values) ? ($values[$lock->key] ?? null) : null;
            $casToken = is_array($entry) ? MemcachedValueGuard::casToken($entry['cas'] ?? null) : null;
            if (!is_array($entry)
                || ($entry['value'] ?? null) !== $lock->token
                || $casToken === null) {
                return;
            }

            $this->memcached->cas(
                $casToken,
                $lock->key,
                self::RELEASED_PREFIX . bin2hex(random_bytes(16)),
                1,
            );
        });
    }

    private function tryAcquire(string $key, string $token, int $ttl): bool
    {
        if ($this->memcached->add($key, $token, $ttl)) {
            return true;
        }

        $entries = $this->memcached->getMulti([$key], \Memcached::GET_EXTENDED);
        $entry = is_array($entries) ? ($entries[$key] ?? null) : null;
        $value = is_array($entry) ? ($entry['value'] ?? null) : null;
        if (!is_string($value) || !str_starts_with($value, self::RELEASED_PREFIX)) {
            return false;
        }

        $cas = is_array($entry) ? MemcachedValueGuard::casToken($entry['cas'] ?? null) : null;

        return $cas !== null && $this->memcached->cas($cas, $key, $token, $ttl);
    }
}
