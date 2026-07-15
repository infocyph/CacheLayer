<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Counter;

use Infocyph\CacheLayer\Counter\Exception\AtomicCounterException;

final readonly class RedisAtomicCounterStore implements AtomicCounterStoreInterface
{
    private const string INCREMENT_SCRIPT = <<<'LUA'
local exists = redis.call('EXISTS', KEYS[1])
local value = redis.call('INCRBY', KEYS[1], ARGV[1])
if exists == 0 and tonumber(ARGV[2]) > 0 then
    redis.call('EXPIRE', KEYS[1], ARGV[2])
end
return { value, exists == 0 and 1 or 0 }
LUA;

    private string $namespace;

    public function __construct(
        private \Redis $client,
        string $namespace = 'default',
    ) {
        if (!class_exists(\Redis::class)) {
            throw new AtomicCounterException('phpredis extension not loaded');
        }

        $this->namespace = sanitize_cache_ns($namespace);
    }

    public function decrement(string $key, int $by = 1, ?int $ttlSeconds = null): AtomicCounterValue
    {
        if ($by < 1) {
            throw new AtomicCounterException('Atomic counter decrement value must be greater than zero.');
        }

        return $this->change($key, -$by, $ttlSeconds);
    }

    public function delete(string $key): bool
    {
        return $this->client->del($this->map($key)) !== false;
    }

    public function get(string $key): ?int
    {
        $value = $this->client->get($this->map($key));
        if ($value === false || $value === null) {
            return null;
        }

        if (!is_string($value) || !preg_match('/^-?\d+$/D', $value)) {
            throw new AtomicCounterException('Atomic counter contains a non-integer value.');
        }

        return (int) $value;
    }

    public function increment(string $key, int $by = 1, ?int $ttlSeconds = null): AtomicCounterValue
    {
        if ($by < 1) {
            throw new AtomicCounterException('Atomic counter increment value must be greater than zero.');
        }

        return $this->change($key, $by, $ttlSeconds);
    }

    private function change(string $key, int $by, ?int $ttlSeconds): AtomicCounterValue
    {
        $ttl = $this->normalizeTtl($ttlSeconds);
        $result = $this->client->eval(self::INCREMENT_SCRIPT, [$this->map($key), (string) $by, (string) $ttl], 1);
        if (!is_array($result) || !isset($result[0], $result[1]) || !is_numeric($result[0]) || !is_numeric($result[1])) {
            throw new AtomicCounterException('Unable to update atomic counter.');
        }

        return new AtomicCounterValue((int) $result[0], (int) $result[1] === 1);
    }

    private function map(string $key): string
    {
        if (!preg_match('/^[A-Za-z0-9_.-]+$/D', $key)) {
            throw new AtomicCounterException('Atomic counter key is invalid.');
        }

        return $this->namespace . ':counter:' . $key;
    }

    private function normalizeTtl(?int $ttlSeconds): int
    {
        if ($ttlSeconds === null) {
            return -1;
        }

        if ($ttlSeconds < 1) {
            throw new AtomicCounterException('Atomic counter TTL must be greater than zero when provided.');
        }

        return $ttlSeconds;
    }
}
