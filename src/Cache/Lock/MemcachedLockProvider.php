<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cache\Lock;

use RuntimeException;

final readonly class MemcachedLockProvider implements LockProviderInterface
{
    use GeneratesLockTokens;
    use PollingLockProviderHelpers;

    private int $retrySleepMicros;

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

    public function acquire(string $key, float $waitSeconds, float $leaseSeconds = 30.0): ?LockHandle
    {
        $ttlSeconds = self::leaseSeconds($leaseSeconds);

        return $this->acquireWithRetry(
            $this->prefix,
            $key,
            $waitSeconds,
            $leaseSeconds,
            fn(string $lockKey, string $token): bool => $this->memcached->add($lockKey, $token, $ttlSeconds),
        );
    }

    public function refresh(?LockHandle $handle, float $leaseSeconds): bool
    {
        if (!$handle instanceof LockHandle) {
            return false;
        }

        $ttlSeconds = self::leaseSeconds($leaseSeconds);
        $values = $this->memcached->getMulti([$handle->key], \Memcached::GET_EXTENDED);
        if (!is_array($values)) {
            return false;
        }

        $entry = $values[$handle->key] ?? null;
        if (!is_array($entry) || ($entry['value'] ?? null) !== $handle->token) {
            return false;
        }

        $casToken = $entry['cas'] ?? null;
        if (!is_float($casToken) && !is_int($casToken)) {
            return false;
        }

        return $this->memcached->cas((float) $casToken, $handle->key, $handle->token, $ttlSeconds);
    }

    public function release(?LockHandle $handle): void
    {
        $this->releaseWithGuard($handle, function (LockHandle $lock): void {
            $values = $this->memcached->getMulti([$lock->key], \Memcached::GET_EXTENDED);
            $entry = is_array($values) ? ($values[$lock->key] ?? null) : null;
            $casToken = is_array($entry) ? ($entry['cas'] ?? null) : null;
            if (
                is_array($entry)
                && ($entry['value'] ?? null) === $lock->token
                && (is_float($casToken) || is_int($casToken))
                && $this->memcached->cas((float) $casToken, $lock->key, $lock->token, 1)
            ) {
                $this->memcached->delete($lock->key);
            }
        });
    }
}
