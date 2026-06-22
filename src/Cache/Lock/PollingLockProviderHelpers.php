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
     * @param string $prefix The prefix argument.
     * @param string $key The key argument.
     * @param float $waitSeconds The wait seconds argument.
     * @param callable $attemptAcquire The attempt acquire argument.
     * @phpstan-param callable(string,string):bool $attemptAcquire
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
     * @param LockHandle|null $handle The handle argument.
     * @param callable $releaser The releaser argument.
     * @phpstan-param callable(LockHandle):void $releaser
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
