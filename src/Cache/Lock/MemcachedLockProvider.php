<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cache\Lock;

use Memcached;
use RuntimeException;

final readonly class MemcachedLockProvider implements LockProviderInterface
{
    use GeneratesLockTokens;
    use PollingLockProviderHelpers;

    private int $retrySleepMicros;

    public function __construct(
        private Memcached $memcached,
        private string $prefix = 'cachelayer:lock:',
        int $retrySleepMicros = 50_000,
    ) {
        if (!class_exists(Memcached::class)) {
            throw new RuntimeException('Memcached extension not loaded');
        }
        $this->retrySleepMicros = self::normalizeRetrySleepMicros($retrySleepMicros);
    }

    public function acquire(string $key, float $waitSeconds): ?LockHandle
    {
        $ttlSeconds = max(1, (int) ceil($waitSeconds + 1.0));

        return $this->acquireWithRetry(
            $this->prefix,
            $key,
            $waitSeconds,
            fn(string $lockKey, string $token, float $unusedWait): bool => $this->memcached->add($lockKey, $token, $ttlSeconds),
        );
    }

    public function release(?LockHandle $handle): void
    {
        $this->releaseWithGuard($handle, function (LockHandle $lock): void {
            $current = $this->memcached->get($lock->key);
            if ($this->memcached->getResultCode() === Memcached::RES_SUCCESS && $current === $lock->token) {
                $this->memcached->delete($lock->key);
            }
        });
    }
}
