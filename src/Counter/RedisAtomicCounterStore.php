<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Counter;

use Infocyph\CacheLayer\Cache\CacheInput;
use Infocyph\CacheLayer\Counter\Exception\AtomicCounterException;
use Infocyph\CacheLayer\Exceptions\CacheInvalidArgumentException;

final readonly class RedisAtomicCounterStore implements AtomicCounterStoreInterface
{
    private const string COUNTER_PREFIX = 'cachelayer:counter:';

    private const string INCREMENT_SCRIPT = <<<'LUA'
local existed = redis.call('EXISTS', KEYS[1])
redis.call('INCRBY', KEYS[1], ARGV[1])
if existed == 0 and tonumber(ARGV[2]) > 0 then
    redis.call('EXPIRE', KEYS[1], ARGV[2])
end
local value = redis.call('GET', KEYS[1])
return { value, existed == 0 and '1' or '0' }
LUA;

    private string $namespace;

    public function __construct(
        private \Redis $client,
        string $namespace = 'default',
    ) {
        if (!class_exists(\Redis::class)) {
            throw new AtomicCounterException('phpredis extension not loaded');
        }

        try {
            $this->namespace = CacheInput::namespace($namespace);
        } catch (CacheInvalidArgumentException $failure) {
            throw new AtomicCounterException($failure->getMessage(), 0, $failure);
        }
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

        if (!is_string($value)) {
            throw new AtomicCounterException('Atomic counter contains a non-integer value.');
        }

        return $this->parseInteger($value);
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

        try {
            $result = $this->client->eval(
                self::INCREMENT_SCRIPT,
                [$this->map($key), (string) $by, (string) $ttl],
                1,
            );
        } catch (\RedisException $failure) {
            throw new AtomicCounterException('Unable to update atomic counter.', 0, $failure);
        }

        if (!is_array($result) || !isset($result[0], $result[1]) || !is_string($result[0])) {
            throw new AtomicCounterException('Unable to update atomic counter.');
        }
        $initialized = match ($result[1]) {
            1, '1' => true,
            0, '0' => false,
            default => throw new AtomicCounterException('Unable to update atomic counter.'),
        };

        return new AtomicCounterValue($this->parseInteger($result[0]), $initialized);
    }

    private function map(string $key): string
    {
        try {
            CacheInput::key($key);
        } catch (CacheInvalidArgumentException $failure) {
            throw new AtomicCounterException($failure->getMessage(), 0, $failure);
        }

        return self::COUNTER_PREFIX . $this->namespace . ':' . $key;
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

    private function parseInteger(string $value): int
    {
        if (preg_match('/^-?\d+$/D', $value) !== 1) {
            throw new AtomicCounterException('Atomic counter contains a non-integer value.');
        }

        $negative = str_starts_with($value, '-');
        $digits = ltrim($negative ? substr($value, 1) : $value, '0');
        $digits = $digits === '' ? '0' : $digits;
        $limit = $negative ? substr((string) PHP_INT_MIN, 1) : (string) PHP_INT_MAX;
        if (strlen($digits) > strlen($limit)
            || (strlen($digits) === strlen($limit) && strcmp($digits, $limit) > 0)) {
            throw new AtomicCounterException('Atomic counter value is outside the PHP integer range.');
        }

        return (int) (($negative && $digits !== '0' ? '-' : '') . $digits);
    }
}
