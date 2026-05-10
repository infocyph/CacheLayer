<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cache\Lock;

use Throwable;

trait PollingLockProviderHelpers
{
    protected static function normalizeRetrySleepMicros(int $retrySleepMicros): int
    {
        return max(1_000, $retrySleepMicros);
    }

    /**
     * @param callable(string,string):bool $attemptAcquire
     */
    protected function acquireWithRetry(
        string $prefix,
        string $key,
        float $waitSeconds,
        callable $attemptAcquire,
    ): ?LockHandle {
        $deadline = microtime(true) + max(0.0, $waitSeconds);
        $lockKey = $prefix . hash('xxh128', $key);
        $token = self::generateToken();
        if ($token === null) {
            return null;
        }

        do {
            if ($attemptAcquire($lockKey, $token)) {
                return new LockHandle($lockKey, $token);
            }

            if (microtime(true) >= $deadline) {
                return null;
            }

            usleep($this->retrySleepMicros);
        } while (true);
    }

    /**
     * @param callable(LockHandle):void $releaser
     */
    protected function releaseWithGuard(?LockHandle $handle, callable $releaser): void
    {
        if (!$handle instanceof LockHandle) {
            return;
        }

        try {
            $releaser($handle);
        } catch (Throwable) {
            // Best effort unlock.
        }
    }
}
