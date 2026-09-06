<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cache\Adapter;

use Infocyph\CacheLayer\Cache\CacheInput;
use Infocyph\CacheLayer\Cache\CacheRecord;
use Infocyph\CacheLayer\Cache\Item\CacheItem;
use Infocyph\CacheLayer\Exceptions\CacheInvalidArgumentException;
use Infocyph\CacheLayer\Support\RedisConnection;
use InvalidArgumentException;
use Psr\Cache\CacheItemInterface;
use RuntimeException;

/**
 * Redis-based cache adapter implementation.
 *
 * This adapter uses Redis to provide high-performance distributed caching.
 * It supports both standalone Redis instances and Redis clusters,
 * making it suitable for production environments with multiple web servers.
 *
 * This This adapter requires the phpredis extension to be installed.
     * @param string $namespace A namespace prefix to avoid key collisions.
     * @param string $dsn The Redis connection DSN (e.g., 'redis://127.0.0.1:6379').
     * @param \Redis|null $client Optional pre-configured Redis client instance.
 */
class RedisCacheAdapter extends AbstractCacheAdapter implements AtomicCachePoolInterface
{
    private const string COMPARE_AND_SET_SCRIPT = <<<'LUA'
local current = redis.call('GET', KEYS[1])
if not current or current ~= ARGV[1] then
    return 0
end
local ttl = tonumber(ARGV[3])
if ttl and ttl > 0 then
    redis.call('SET', KEYS[1], ARGV[2], 'EX', ttl)
else
    redis.call('SET', KEYS[1], ARGV[2])
end
return 1
LUA;

    private const string GET_AND_DELETE_SCRIPT = <<<'LUA'
local value = redis.call('GET', KEYS[1])
if not value then
    return false
end
redis.call('DEL', KEYS[1])
return value
LUA;

    private const string REPLACE_STALE_SCRIPT = <<<'LUA'
local current = redis.call('GET', KEYS[1])
if current and current ~= ARGV[1] then
    return 0
end
if tonumber(ARGV[3]) > 0 then
    redis.call('SET', KEYS[1], ARGV[2], 'EX', tonumber(ARGV[3]))
else
    redis.call('SET', KEYS[1], ARGV[2])
end
return 1
LUA;

    private readonly string $ns;

    private readonly \Redis $redis;

    /**
     * Creates a new Redis cache adapter.
     *
     *
     * @throws RuntimeException If the phpredis extension is not loaded.
     * @param string $namespace A namespace prefix to avoid key collisions.
     * @param string $dsn The Redis connection DSN (e.g., 'redis://127.0.0.1:6379').
     * @param \Redis|null $client Optional pre-configured Redis client instance.
     */
    public function __construct(
        string $namespace = 'default',
        string $dsn = 'redis://127.0.0.1:6379',
        ?\Redis $client = null,
    ) {
        if (!class_exists(\Redis::class)) {
            throw new RuntimeException('phpredis extension not loaded');
        }

        $this->ns = CacheInput::namespace($namespace);
        $this->redis = $client ?? $this->connect($dsn);
    }

    public function atomicCompareAndSet(
        string $key,
        mixed $expected,
        CacheItemInterface $replacement,
    ): bool {
        if (!$this->supportsItem($replacement)) {
            throw new CacheInvalidArgumentException('The cache item belongs to another pool.');
        }

        $expiration = CachePayloadCodec::expirationFromItem($replacement);
        $ttl = $expiration['ttl'];
        if ($ttl !== null && $ttl <= 0) {
            return false;
        }

        $mapped = $this->map($key);
        $existing = $this->redis->get($mapped);
        if (!is_string($existing)) {
            return false;
        }
        $record = $this->decodeRecordFromBlob($existing);
        if (!$record instanceof CacheRecord || $record->tags !== [] || $record->value !== $expected) {
            return false;
        }

        $blob = $this->encodeItem($replacement, $expiration['expiresAt']);
        $result = $this->redis->eval(
            self::COMPARE_AND_SET_SCRIPT,
            [$mapped, $existing, $blob, (string) ($ttl ?? 0)],
            1,
        );

        return AdapterValueNormalizer::intOrZero($result) === 1;
    }

    public function atomicGetAndDelete(string $key): CacheItemInterface
    {
        $raw = $this->redis->eval(self::GET_AND_DELETE_SCRIPT, [$this->map($key)], 1);
        if (!is_string($raw)) {
            return $this->genericMiss($key);
        }

        $record = $this->decodeRecordFromBlob($raw);
        if (!$record instanceof CacheRecord || !$this->recordTagsAreCurrent($record)) {
            return $this->genericMiss($key);
        }

        return $this->genericItemFromRecord($key, $record);
    }

    public function atomicSetIfAbsent(CacheItemInterface $item): bool
    {
        if (!$this->supportsItem($item)) {
            throw new CacheInvalidArgumentException('The cache item belongs to another pool.');
        }

        $expiration = CachePayloadCodec::expirationFromItem($item);
        $ttl = $expiration['ttl'];
        if ($ttl !== null && $ttl <= 0) {
            return false;
        }

        $key = $this->map($item->getKey());
        $blob = $this->encodeItem($item, $expiration['expiresAt']);
        $options = ['nx'];
        if ($ttl !== null) {
            $options['ex'] = max(1, $ttl);
        }
        if ($this->redis->set($key, $blob, $options)) {
            return true;
        }

        $existing = $this->redis->get($key);
        if (!is_string($existing)) {
            return (bool) $this->redis->set($key, $blob, $options);
        }

        $record = $this->decodeRecordFromBlob($existing);
        if ($record instanceof CacheRecord && $this->recordTagsAreCurrent($record)) {
            return false;
        }

        $result = $this->redis->eval(
            self::REPLACE_STALE_SCRIPT,
            [$key, $existing, $blob, (string) ($ttl ?? 0)],
            1,
        );

        return AdapterValueNormalizer::intOrZero($result) === 1;
    }

    public function clear(): bool
    {
        $cursor = null;
        do {
            $keys = $this->redis->scan($cursor, $this->ns . ':*', 1000);
            if ($keys) {
                $this->redis->del($keys);
            }
        } while ($cursor);
        $this->deferred = [];

        return true;
    }

    public function deleteItem(string $key): bool
    {
        return $this->redis->del($this->map($key)) !== false;
    }

    /**
     * @param array $keys The keys argument.
     * @phpstan-param list<string> $keys
     */
    public function deleteItems(array $keys): bool
    {
        if ($keys === []) {
            return true;
        }

        $full = array_map($this->map(...), $keys);

        return $this->redis->del($full) !== false;
    }

    public function getClient(): \Redis
    {
        return $this->redis;
    }

    public function getItem(string $key): CacheItem
    {
        $raw = $this->redis->get($this->map($key));
        if (is_string($raw)) {
            $record = $this->decodeRecordFromBlob($raw);
            if ($record !== null) {
                return $this->genericItemFromRecord($key, $record);
            }
            $this->redis->del($this->map($key));
        }

        return new CacheItem($this, $key);
    }

    /** @param list<string> $tags */
    #[\Override]
    public function getTagGenerations(array $tags): array
    {
        if ($tags === []) {
            return [];
        }

        $values = $this->redis->mget(array_map($this->mapTag(...), $tags));
        $values = is_array($values) ? array_values($values) : [];
        $generations = [];
        $missing = [];
        foreach ($tags as $index => $tag) {
            $value = $values[$index] ?? null;
            $generation = self::normalizeGeneration($value);
            if ($generation === null) {
                $missing[$tag] = $value;

                continue;
            }
            $generations[$tag] = $generation;
        }

        return $generations + $this->initializeTagGenerations($missing);
    }

    public function hasItem(string $key): bool
    {
        return $this->redis->exists($this->map($key)) === 1;
    }

    /**
     * @param array $keys The keys argument.
     * @phpstan-param list<string> $keys
     * @phpstan-return array<string, CacheItem>
     */
    public function multiFetch(array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        $prefixed = array_map($this->map(...), $keys);
        $rawVals = $this->redis->mget($prefixed);
        if (!is_array($rawVals)) {
            $rawVals = [];
        }
        $rawVals = array_values($rawVals);

        $items = [];
        $stale = [];
        foreach ($keys as $idx => $k) {
            $v = $rawVals[$idx] ?? null;
            if ($v !== null && $v !== false) {
                if (!is_string($v)) {
                    $items[$k] = new CacheItem($this, $k);

                    continue;
                }

                $record = $this->decodeRecordFromBlob($v);
                if ($record !== null) {
                    $items[$k] = $this->genericItemFromRecord($k, $record);

                    continue;
                }
                $stale[] = $this->map($k);
            }
            $items[$k] = new CacheItem($this, $k);
        }

        if ($stale !== []) {
            $this->redis->del($stale);
        }

        return $items;
    }

    /** @param list<string> $tags */
    #[\Override]
    public function rotateTagGenerations(array $tags): bool
    {
        if ($tags === []) {
            return true;
        }
        $generations = [];
        foreach ($tags as $tag) {
            $generations[$this->mapTag($tag)] = self::newGeneration();
        }

        return $this->redis->mset($generations);
    }

    public function save(CacheItemInterface $item): bool
    {
        if (!$this->supportsItem($item)) {
            throw new CacheInvalidArgumentException('The cache item belongs to another pool.');
        }

        $expires = CachePayloadCodec::expirationFromItem($item);
        $ttl = $expires['ttl'];
        if ($ttl !== null && $ttl <= 0) {
            $this->redis->del($this->map($item->getKey()));

            return true;
        }

        $blob = $this->encodeItem($item, $expires['expiresAt']);

        return $ttl === null
            ? $this->redis->set($this->map($item->getKey()), $blob)
            : $this->redis->setex($this->map($item->getKey()), max(1, $ttl), $blob);
    }

    /** @param array<string, CacheItemInterface> $items */
    public function saveItems(array $items): bool
    {
        if (!$this->supportsItems($items)) {
            return false;
        }

        $plain = [];
        $expiring = [];
        $expired = [];
        foreach ($items as $item) {
            $expiration = CachePayloadCodec::expirationFromItem($item);
            if ($expiration['ttl'] !== null && $expiration['ttl'] <= 0) {
                $expired[] = $this->map($item->getKey());

                continue;
            }
            $blob = $this->encodeItem($item, $expiration['expiresAt']);
            if ($expiration['ttl'] === null) {
                $plain[$this->map($item->getKey())] = $blob;
            } else {
                $expiring[] = [$this->map($item->getKey()), max(1, $expiration['ttl']), $blob];
            }
        }

        if ($expired !== []) {
            $this->redis->del($expired);
        }

        $ok = $plain === [] || $this->redis->mset($plain);
        if ($expiring === []) {
            return $ok;
        }

        return $ok && $this->saveExpiring($expiring);
    }

    private function connect(string $dsn): \Redis
    {
        try {
            return RedisConnection::connect($dsn);
        } catch (InvalidArgumentException $exception) {
            throw new RuntimeException("Invalid Redis DSN: $dsn", 0, $exception);
        }
    }

    /**
     * @param array<string, mixed> $missing
     * @return array<string, string>
     */
    private function initializeTagGenerations(array $missing): array
    {
        $generations = [];
        foreach ($missing as $tag => $value) {
            $candidate = self::newGeneration();
            $key = $this->mapTag($tag);
            if ($value === false || $value === null) {
                $stored = $this->redis->set($key, $candidate, ['nx']);
                $current = $stored ? $candidate : $this->redis->get($key);
            } else {
                $this->redis->set($key, $candidate);
                $current = $candidate;
            }
            $generation = self::normalizeGeneration($current);
            if ($generation === null) {
                throw new RuntimeException('Unable to initialize Redis tag generation.');
            }
            $generations[$tag] = $generation;
        }

        return $generations;
    }

    private function map(string $key): string
    {
        return $this->ns . ':d:' . $key;
    }

    private function mapTag(string $tag): string
    {
        return $this->ns . ':m:tag:' . $tag;
    }

    private function recordTagsAreCurrent(CacheRecord $record): bool
    {
        if ($record->tags === []) {
            return true;
        }

        $current = $this->getTagGenerations(array_keys($record->tags));
        foreach ($record->tags as $tag => $generation) {
            if (($current[$tag] ?? null) !== $generation) {
                return false;
            }
        }

        return true;
    }

    /** @param list<array{0:string, 1:int, 2:string}> $records */
    private function saveExpiring(array $records): bool
    {
        $pipeline = $this->redis->multi(\Redis::PIPELINE);
        foreach ($records as [$key, $ttl, $blob]) {
            $pipeline->setex($key, $ttl, $blob);
        }
        $results = $pipeline->exec();

        return is_array($results)
            && count($results) === count($records)
            && AdapterValueNormalizer::allTrue($results);
    }
}
