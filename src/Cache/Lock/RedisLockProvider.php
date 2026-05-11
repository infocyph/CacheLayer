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

    public function acquire(string $key, float $waitSeconds): ?LockHandle
    {
        $ttlMs = max(1_000, (int) ceil(($waitSeconds + 1.0) * 1000));

        return $this->acquireWithRetry(
            $this->prefix,
            $key,
            $waitSeconds,
            fn(string $lockKey, string $token): bool => (bool) $this->redis->set($lockKey, $token, ['nx', 'px' => $ttlMs]),
        );
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
