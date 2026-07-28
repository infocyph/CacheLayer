<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cache\Lock;

use RuntimeException;

final readonly class RedisLockProvider implements LockProviderInterface
{
    use GeneratesLockTokens;
    use PollingLockProviderHelpers;

    private int $retrySleepMicros;

    public function __construct(
        private \Redis $redis,
        private string $prefix = 'cachelayer:lock:',
        int $retrySleepMicros = 50_000,
    ) {
        $this->assertRedisExtensionLoaded();
        $this->retrySleepMicros = self::normalizeRetrySleepMicros($retrySleepMicros);
    }

    public function acquire(string $key, float $waitSeconds, float $leaseSeconds = 30.0): ?LockHandle
    {
        $ttlMs = self::leaseMilliseconds($leaseSeconds);

        return $this->acquireWithRetry(
            $this->prefix,
            $key,
            $waitSeconds,
            $leaseSeconds,
            fn(string $lockKey, string $token): bool => (bool) $this->redis->set($lockKey, $token, ['nx', 'px' => $ttlMs]),
        );
    }

    public function refresh(?LockHandle $handle, float $leaseSeconds): bool
    {
        if (!$handle instanceof LockHandle) {
            return false;
        }

        $ttlMs = self::leaseMilliseconds($leaseSeconds);
        $script = <<<'LUA'
if redis.call("GET", KEYS[1]) == ARGV[1] then
    return redis.call("PEXPIRE", KEYS[1], ARGV[2])
end
return 0
LUA;

        $result = $this->redis->eval($script, [$handle->key, $handle->token, $ttlMs], 1);

        return $result === 1 || $result === '1';
    }

    public function release(?LockHandle $handle): void
    {
        $script = <<<'LUA'
if redis.call("GET", KEYS[1]) == ARGV[1] then
    return redis.call("DEL", KEYS[1])
end
return 0
LUA;

        $this->releaseWithGuard(
            $handle,
            function (LockHandle $lock) use ($script): void {
                $this->redis->eval($script, [$lock->key, $lock->token], 1);
            },
        );
    }

    private function assertRedisExtensionLoaded(): void
    {
        if (!class_exists(\Redis::class)) {
            throw new RuntimeException('phpredis extension not loaded');
        }
    }
}
