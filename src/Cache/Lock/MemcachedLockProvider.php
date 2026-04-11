<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cache\Lock;

use Memcached;
use RuntimeException;
use Throwable;

final readonly class MemcachedLockProvider implements LockProviderInterface
{
    private int $retrySleepMicros;

    public function __construct(
        private Memcached $memcached,
        private string $prefix = 'cachelayer:lock:',
        int $retrySleepMicros = 50_000,
    ) {
        if (!class_exists(Memcached::class)) {
            throw new RuntimeException('Memcached extension not loaded');
        }
        $this->retrySleepMicros = max(1_000, $retrySleepMicros);
    }

    public function acquire(string $key, float $waitSeconds): ?LockHandle
    {
        $deadline = microtime(true) + max(0.0, $waitSeconds);
        $lockKey = $this->prefix . hash('xxh128', $key);
        $token = hash('xxh128', uniqid($key, true));
        $ttlSeconds = max(1, (int) ceil($waitSeconds + 1.0));

        do {
            if ($this->memcached->add($lockKey, $token, $ttlSeconds)) {
                return new LockHandle($lockKey, $token);
            }

            if (microtime(true) >= $deadline) {
                return null;
            }

            usleep($this->retrySleepMicros);
        } while (true);
    }

    public function release(?LockHandle $handle): void
    {
        if (!$handle instanceof LockHandle) {
            return;
        }

        try {
            $current = $this->memcached->get($handle->key);
            if ($this->memcached->getResultCode() === Memcached::RES_SUCCESS && $current === $handle->token) {
                $this->memcached->delete($handle->key);
            }
        } catch (Throwable) {
            // Best effort unlock.
        }
    }
}
