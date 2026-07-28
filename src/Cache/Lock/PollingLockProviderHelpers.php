<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cache\Lock;

use Throwable;

trait PollingLockProviderHelpers
{
    protected static function leaseMilliseconds(float $leaseSeconds): int
    {
        if ($leaseSeconds <= 0) {
            throw new \InvalidArgumentException('Lock lease duration must be positive.');
        }

        return max(1, (int) ceil($leaseSeconds * 1000));
    }

    protected static function leaseSeconds(float $leaseSeconds): int
    {
        if ($leaseSeconds <= 0) {
            throw new \InvalidArgumentException('Lock lease duration must be positive.');
        }

        return max(1, (int) ceil($leaseSeconds));
    }

    protected static function normalizeRetrySleepMicros(int $retrySleepMicros): int
    {
        return max(1_000, $retrySleepMicros);
    }

    /**
     * @param string $prefix The prefix argument.
     * @param string $key The key argument.
     * @param float $waitSeconds The wait seconds argument.
     * @param float $leaseSeconds The lease seconds argument.
     * @param callable $attemptAcquire The attempt acquire argument.
     * @phpstan-param callable(string,string):bool $attemptAcquire
     */
    protected function acquireWithRetry(
        string $prefix,
        string $key,
        float $waitSeconds,
        float $leaseSeconds,
        callable $attemptAcquire,
    ): ?LockHandle {
        if ($leaseSeconds <= 0) {
            throw new \InvalidArgumentException('Lock lease duration must be positive.');
        }

        $deadline = microtime(true) + max(0.0, $waitSeconds);
        $lockKey = $prefix . self::digestLockKey($key);
        $token = self::generateToken();
        if ($token === null) {
            return null;
        }

        do {
            if ($attemptAcquire($lockKey, $token)) {
                return new LockHandle($lockKey, $token, leaseSeconds: $leaseSeconds);
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
