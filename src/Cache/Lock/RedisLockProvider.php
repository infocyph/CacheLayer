<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cache\Lock;

use Redis;
use RuntimeException;
use Throwable;

final readonly class RedisLockProvider implements LockProviderInterface
{
    private int $retrySleepMicros;

    public function __construct(
        private Redis $redis,
        private string $prefix = 'cachelayer:lock:',
        int $retrySleepMicros = 50_000,
    ) {
        if (!class_exists(Redis::class)) {
            throw new RuntimeException('phpredis extension not loaded');
        }
        $this->retrySleepMicros = max(1_000, $retrySleepMicros);
    }

    public function acquire(string $key, float $waitSeconds): ?LockHandle
    {
        $deadline = microtime(true) + max(0.0, $waitSeconds);
        $lockKey = $this->prefix . hash('xxh128', $key);
        $token = self::generateToken();
        if ($token === null) {
            return null;
        }
        $ttlMs = max(1_000, (int) ceil(($waitSeconds + 1.0) * 1000));

        do {
            $ok = $this->redis->set($lockKey, $token, ['nx', 'px' => $ttlMs]);
            if ($ok) {
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

        $script = <<<'LUA'
if redis.call("GET", KEYS[1]) == ARGV[1] then
    return redis.call("DEL", KEYS[1])
end
return 0
LUA;

        try {
            $this->redis->eval($script, [$handle->key, $handle->token], 1);
        } catch (Throwable) {
            // Best effort unlock.
        }
    }

    private static function generateToken(): ?string
    {
        try {
            return bin2hex(random_bytes(16));
        } catch (Throwable) {
            return null;
        }
    }
}
