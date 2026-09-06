<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cache\Adapter;

use Infocyph\CacheLayer\Cache\CacheRecord;
use Psr\Cache\CacheItemInterface;

/** @internal Redis Cluster atomic protocol kept separate from normal cache mechanics. */
trait RedisClusterAtomicOperations
{
    private const string ATOMIC_COMPARE_AND_SET_SCRIPT = <<<'LUA'
-- cachelayer:atomic-compare-and-set
local generation = redis.call('GET', KEYS[1])
if generation ~= ARGV[1] then
    return 0
end
local current = redis.call('GET', KEYS[2])
if not current or current ~= ARGV[2] then
    return 0
end
local ttl = tonumber(ARGV[4])
if ttl and ttl > 0 then
    redis.call('SET', KEYS[2], ARGV[3], 'EX', ttl)
else
    redis.call('SET', KEYS[2], ARGV[3])
end
return 1
LUA;

    private const string ATOMIC_GET_AND_DELETE_SCRIPT = <<<'LUA'
-- cachelayer:atomic-get-and-delete
local generation = redis.call('GET', KEYS[1])
local value = redis.call('GET', KEYS[2])
if not value then
    return {generation or false, false}
end
redis.call('DEL', KEYS[2])
return {generation or false, value}
LUA;

    private const string ATOMIC_SET_IF_ABSENT_SCRIPT = <<<'LUA'
-- cachelayer:atomic-set-if-absent
local generation = redis.call('GET', KEYS[1])
if generation ~= ARGV[1] then
    return -1
end
local current = redis.call('GET', KEYS[2])
if current then
    if ARGV[4] ~= '1' or current ~= ARGV[5] then
        return 0
    end
end
local ttl = tonumber(ARGV[3])
if ttl and ttl > 0 then
    redis.call('SET', KEYS[2], ARGV[2], 'EX', ttl)
else
    redis.call('SET', KEYS[2], ARGV[2])
end
return 1
LUA;

    public function atomicCompareAndSet(
        string $key,
        mixed $expected,
        CacheItemInterface $replacement,
    ): bool {
        if (!$this->supportsItem($replacement)) {
            return false;
        }
        $expiration = CachePayloadCodec::expirationFromItem($replacement);
        if ($expiration['ttl'] !== null && $expiration['ttl'] <= 0) {
            return false;
        }

        $state = $this->atomicReadState($key);
        if (!is_string($state['existing'])) {
            return false;
        }
        $record = $this->decodeRecordFromBlob($state['existing']);
        if (!$record instanceof CacheRecord
            || $record->namespaceGeneration !== $state['generation']
            || $record->tags !== []
            || $record->value !== $expected) {
            return false;
        }

        $blob = $this->encodeItem($replacement, $expiration['expiresAt'], $state['generation']);
        $result = $this->call(
            'eval',
            self::ATOMIC_COMPARE_AND_SET_SCRIPT,
            [
                $state['generationKey'],
                $state['dataKey'],
                $state['generation'],
                $state['existing'],
                $blob,
                (string) ($expiration['ttl'] ?? 0),
            ],
            2,
        );

        return AdapterValueNormalizer::intOrZero($result) === 1;
    }

    public function atomicGetAndDelete(string $key): CacheItemInterface
    {
        $bucket = $this->bucket($key);
        $result = $this->call(
            'eval',
            self::ATOMIC_GET_AND_DELETE_SCRIPT,
            [$this->generationKey($bucket), $this->mapData($key)],
            2,
        );
        if (!is_array($result)) {
            return $this->genericMiss($key);
        }

        $generation = self::normalizeGeneration($result[0] ?? null);
        $blob = $result[1] ?? null;
        if ($generation === null || !is_string($blob)) {
            return $this->genericMiss($key);
        }

        $record = $this->decodeRecordFromBlob($blob);
        if (!$record instanceof CacheRecord
            || $record->namespaceGeneration !== $generation
            || !$this->recordTagsAreCurrent($record)) {
            return $this->genericMiss($key);
        }

        return $this->genericItemFromRecord($key, $record);
    }

    public function atomicSetIfAbsent(CacheItemInterface $item): bool
    {
        if (!$this->supportsItem($item)) {
            return false;
        }
        $expiration = CachePayloadCodec::expirationFromItem($item);
        if ($expiration['ttl'] !== null && $expiration['ttl'] <= 0) {
            return false;
        }

        for ($attempt = 0; $attempt < 3; ++$attempt) {
            $result = $this->atomicSetIfAbsentAttempt($item, $expiration);
            if ($result !== -1) {
                return $result === 1;
            }
        }

        return false;
    }

    /**
     * @return array{bucket:int,generationKey:string,dataKey:string,generation:string,existing:mixed}
     */
    private function atomicReadState(string $key): array
    {
        $bucket = $this->bucket($key);
        $generationKey = $this->generationKey($bucket);
        $dataKey = $this->mapData($key);
        $values = $this->call('mget', [$generationKey, $dataKey]);
        $values = is_array($values) ? array_values($values) : [];

        return [
            'bucket' => $bucket,
            'generationKey' => $generationKey,
            'dataKey' => $dataKey,
            'generation' => $this->namespaceGeneration($bucket, $values[0] ?? null),
            'existing' => $values[1] ?? null,
        ];
    }

    /**
     * @param array{ttl:int|null,expiresAt:int|null} $expiration
     */
    private function atomicSetIfAbsentAttempt(CacheItemInterface $item, array $expiration): int
    {
        $state = $this->atomicReadState($item->getKey());
        $replaceStale = false;
        $expectedExisting = '';
        if (is_string($state['existing'])) {
            $record = $this->decodeRecordFromBlob($state['existing']);
            if ($record instanceof CacheRecord
                && $record->namespaceGeneration === $state['generation']
                && $this->recordTagsAreCurrent($record)) {
                return 0;
            }
            $replaceStale = true;
            $expectedExisting = $state['existing'];
        }

        $blob = $this->encodeItem($item, $expiration['expiresAt'], $state['generation']);
        $result = $this->call(
            'eval',
            self::ATOMIC_SET_IF_ABSENT_SCRIPT,
            [
                $state['generationKey'],
                $state['dataKey'],
                $state['generation'],
                $blob,
                (string) ($expiration['ttl'] ?? 0),
                $replaceStale ? '1' : '0',
                $expectedExisting,
            ],
            2,
        );

        return AdapterValueNormalizer::intOrZero($result);
    }
}
