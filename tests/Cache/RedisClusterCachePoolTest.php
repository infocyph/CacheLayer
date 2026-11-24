<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cache\AtomicCacheInterface;
use Infocyph\CacheLayer\Cache\Cache;

final class CacheLayerRedisClusterDouble
{
    /** @var array<string, array{value:string,expires:int|null}> */
    private array $values = [];

    public int $mgetCalls = 0;

    public int $transactionCalls = 0;

    public int $setexCalls = 0;

    public bool $failTransactionCommand = false;

    private bool $transactional = false;

    /** @var list<bool> */
    private array $transactionResults = [];

    public function del(string|array $keys): int
    {
        $deleted = 0;
        foreach (is_array($keys) ? $keys : [$keys] as $key) {
            if (isset($this->values[$key])) {
                unset($this->values[$key]);
                $deleted++;
            }
        }

        return $deleted;
    }

    /** @param list<mixed> $arguments */
    public function eval(string $script, array $arguments, int $numKeys): mixed
    {
        if ($numKeys !== 2 || !is_string($arguments[0] ?? null) || !is_string($arguments[1] ?? null)) {
            return false;
        }
        $generationKey = $arguments[0];
        $dataKey = $arguments[1];

        if (str_contains($script, 'cachelayer:atomic-get-and-delete')) {
            $generation = $this->get($generationKey);
            $value = $this->get($dataKey);
            if ($value !== false) {
                unset($this->values[$dataKey]);
            }

            return [$generation, $value];
        }

        if (str_contains($script, 'cachelayer:atomic-compare-and-set')) {
            $expectedGeneration = $arguments[2] ?? null;
            $expectedExisting = $arguments[3] ?? null;
            $blob = $arguments[4] ?? null;
            $ttl = (int) ($arguments[5] ?? 0);
            if (!is_string($expectedGeneration) || !is_string($expectedExisting) || !is_string($blob)) {
                return false;
            }
            if ($this->get($generationKey) !== $expectedGeneration) {
                return -1;
            }
            if ($this->get($dataKey) !== $expectedExisting) {
                return 0;
            }

            $this->values[$dataKey] = [
                'value' => $blob,
                'expires' => $ttl > 0 ? time() + $ttl : null,
            ];

            return 1;
        }

        if (!str_contains($script, 'cachelayer:atomic-set-if-absent')) {
            return false;
        }
        $expectedGeneration = $arguments[2] ?? null;
        $blob = $arguments[3] ?? null;
        $ttl = (int) ($arguments[4] ?? 0);
        $replaceStale = ($arguments[5] ?? '0') === '1';
        $expectedExisting = $arguments[6] ?? null;
        if (!is_string($expectedGeneration) || !is_string($blob)) {
            return false;
        }
        if ($this->get($generationKey) !== $expectedGeneration) {
            return -1;
        }
        $current = $this->get($dataKey);
        if ($current !== false && (!$replaceStale || $current !== $expectedExisting)) {
            return 0;
        }

        $this->values[$dataKey] = [
            'value' => $blob,
            'expires' => $ttl > 0 ? time() + $ttl : null,
        ];

        return 1;
    }

    public function exists(string $key): int
    {
        return $this->get($key) === false ? 0 : 1;
    }

    public function get(string $key): string|false
    {
        $this->prune($key);

        return $this->values[$key]['value'] ?? false;
    }

    public function incr(string $key): int
    {
        $next = (int) ($this->get($key) ?: 0) + 1;
        $this->set($key, (string) $next);

        return $next;
    }

    /** @param list<string> $keys */
    public function mget(array $keys): array
    {
        $this->mgetCalls++;

        return array_map(fn(string $key): string|false => $this->get($key), $keys);
    }

    /** @param array<string, string> $values */
    public function mset(array $values): bool
    {
        foreach ($values as $key => $value) {
            $this->set($key, $value);
        }

        return true;
    }

    public function multi(int $mode): self
    {
        if ($mode !== 1) {
            throw new RuntimeException('Redis Cluster supports MULTI transactions only.');
        }
        $this->transactionCalls++;
        $this->transactional = $mode > 0;
        $this->transactionResults = [];

        return $this;
    }

    /** @param list<string> $options */
    public function set(string $key, string $value, array $options = []): bool
    {
        if (in_array('nx', $options, true) && isset($this->values[$key])) {
            return false;
        }
        $this->values[$key] = ['value' => $value, 'expires' => null];

        return true;
    }

    public function setex(string $key, int $ttl, string $value): bool
    {
        $this->setexCalls++;
        $this->values[$key] = ['value' => $value, 'expires' => time() + max(1, $ttl)];
        if ($this->transactional) {
            $this->transactionResults[] = !$this->failTransactionCommand;
        }

        return true;
    }

    /** @return list<bool> */
    public function exec(): array
    {
        $this->transactional = false;

        return $this->transactionResults;
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->values);
    }

    public function dropGenerationFor(string $logicalKey): void
    {
        foreach (array_keys($this->values) as $physicalKey) {
            if (!str_ends_with($physicalKey, ':d:' . $logicalKey)) {
                continue;
            }
            $prefix = substr($physicalKey, 0, -strlen(':d:' . $logicalKey));
            unset($this->values[$prefix . ':m:generation']);
        }
    }

    private function prune(string $key): void
    {
        $expires = $this->values[$key]['expires'] ?? null;
        if ($expires !== null && $expires <= time()) {
            unset($this->values[$key]);
        }
    }
}

$redisClusterFixture = new class {
    public CacheLayerRedisClusterDouble $cluster;

    public Cache $cache;
};

beforeEach(function () use ($redisClusterFixture): void {
    $redisClusterFixture->cluster = new CacheLayerRedisClusterDouble();

    $redisClusterFixture->cache = Cache::redisCluster(
        'cluster-tests',
        ['127.0.0.1:7000'],
        1.0,
        1.0,
        false,
        $redisClusterFixture->cluster,
    );
});

test('redis cluster adapter bulk-fetches cross-slot values', function () use ($redisClusterFixture): void {
    $redisClusterFixture->cache->setMultiple(['alpha' => 'A', 'beta' => 'B', 'gamma' => 'C']);
    $before = $redisClusterFixture->cluster->mgetCalls;

    expect($redisClusterFixture->cache->getMultiple(['alpha', 'missing', 'beta']))
        ->toBe(['alpha' => 'A', 'missing' => null, 'beta' => 'B'])
        ->and($redisClusterFixture->cluster->mgetCalls)->toBeGreaterThan($before);
});

test('redis cluster adapter honors ttl', function () use ($redisClusterFixture): void {
    $redisClusterFixture->cache->set('ttl', 'v', 1);
    usleep(2_000_000);

    expect($redisClusterFixture->cache->get('ttl'))->toBeNull();
});

test('redis cluster adapter rejects a partial expiring transaction write', function () use ($redisClusterFixture): void {
    $redisClusterFixture->cluster->failTransactionCommand = true;

    expect($redisClusterFixture->cache->setMultiple(['first' => 1, 'second' => 2], 60))->toBeFalse();
});

test('redis cluster groups expiring bulk writes into bounded bucket transactions', function () use ($redisClusterFixture): void {
    $values = [];
    $buckets = [];
    for ($index = 0; $index < 100; $index++) {
        $key = 'bucketed.' . $index;
        $values[$key] = $index;
        $buckets[hexdec(substr(hash('xxh3', $key), 0, 8)) % 128] = true;
    }

    expect($redisClusterFixture->cache->setMultiple($values, 60))->toBeTrue()
        ->and($redisClusterFixture->cluster->setexCalls)->toBe(100)
        ->and($redisClusterFixture->cluster->transactionCalls)->toBe(count($buckets));
});

test('redis cluster clear rotates bucket generations without a permanent key index', function () use ($redisClusterFixture): void {
    $redisClusterFixture->cache->setMultiple(['a' => 1, 'b' => 2]);
    $redisClusterFixture->cache->clear();

    expect($redisClusterFixture->cache->getMultiple(['a', 'b']))->toBe(['a' => null, 'b' => null])
        ->and(implode('|', $redisClusterFixture->cluster->keys()))->not->toContain('__keys');
});

test('missing bucket generation cannot resurrect data written before clear', function () use ($redisClusterFixture): void {
    $redisClusterFixture->cache->set('old', 'stale');
    $redisClusterFixture->cache->clear();
    $redisClusterFixture->cluster->dropGenerationFor('old');

    expect($redisClusterFixture->cache->get('old'))->toBeNull();
});

test('redis cluster exposes atomic capability with one-winner semantics', function () use ($redisClusterFixture): void {
    $atomic = $redisClusterFixture->cache->atomic() ?? throw new \RuntimeException('The test requires atomic cache capability.');

    expect($atomic->setIfAbsent('claim', 'first', 30))->toBeTrue()
        ->and($atomic->setIfAbsent('claim', 'second', 30))->toBeFalse()
        ->and($redisClusterFixture->cache->get('claim'))->toBe('first');
});

test('redis cluster atomic compare-and-set uses strict live-value semantics', function () use ($redisClusterFixture): void {
    $atomic = $redisClusterFixture->cache->atomic() ?? throw new \RuntimeException('The test requires atomic cache capability.');
    $redisClusterFixture->cache->set('cas', 1, 30);

    expect($atomic->compareAndSet('cas', '1', 2, 30))->toBeFalse()
        ->and($atomic->compareAndSet('cas', 1, 2, 30))->toBeTrue()
        ->and($atomic->compareAndSet('cas', 1, 3, 30))->toBeFalse()
        ->and($redisClusterFixture->cache->get('cas'))->toBe(2);
});

test('redis cluster atomic compare-and-set rejects tagged and cleared values', function () use ($redisClusterFixture): void {
    $atomic = $redisClusterFixture->cache->atomic() ?? throw new \RuntimeException('The test requires atomic cache capability.');

    $redisClusterFixture->cache->setTagged('tagged', 'v1', ['group'], 30);
    expect($atomic->compareAndSet('tagged', 'v1', 'v2', 30))->toBeFalse();

    $redisClusterFixture->cache->set('cleared', 'v1', 30);
    $redisClusterFixture->cache->clear();

    expect($atomic->compareAndSet('cleared', 'v1', 'v2', 30))->toBeFalse();
});

test('redis cluster atomic compare-and-set replacement honors ttl', function () use ($redisClusterFixture): void {
    $atomic = $redisClusterFixture->cache->atomic() ?? throw new \RuntimeException('The test requires atomic cache capability.');
    $redisClusterFixture->cache->set('cas-ttl', 'v1', 30);

    expect($atomic->compareAndSet('cas-ttl', 'v1', 'v2', 1))->toBeTrue();
    usleep(2_000_000);

    expect($redisClusterFixture->cache->get('cas-ttl'))->toBeNull();
});

test('redis cluster atomic consume returns a value once', function () use ($redisClusterFixture): void {
    $atomic = $redisClusterFixture->cache->atomic() ?? throw new \RuntimeException('The test requires atomic cache capability.');
    $redisClusterFixture->cache->set('consume', ['ok' => true], 30);

    expect($atomic->getAndDelete('consume', 'missing'))->toBe(['ok' => true])
        ->and($atomic->getAndDelete('consume', 'missing'))->toBe('missing');
});

test('redis cluster atomic set replaces data invalidated by clear', function () use ($redisClusterFixture): void {
    $atomic = $redisClusterFixture->cache->atomic() ?? throw new \RuntimeException('The test requires atomic cache capability.');
    $redisClusterFixture->cache->set('claim', 'stale', 30);
    $redisClusterFixture->cache->clear();

    expect($atomic->setIfAbsent('claim', 'fresh', 30))->toBeTrue()
        ->and($redisClusterFixture->cache->get('claim'))->toBe('fresh');
});

test('redis cluster atomic consume rejects data invalidated by clear', function () use ($redisClusterFixture): void {
    $atomic = $redisClusterFixture->cache->atomic() ?? throw new \RuntimeException('The test requires atomic cache capability.');
    $redisClusterFixture->cache->set('consume', 'stale', 30);
    $redisClusterFixture->cache->clear();

    expect($atomic->getAndDelete('consume', 'missing'))->toBe('missing')
        ->and($redisClusterFixture->cache->get('consume'))->toBeNull();
});

test('redis cluster atomic ttl expires before the next claim', function () use ($redisClusterFixture): void {
    $atomic = $redisClusterFixture->cache->atomic() ?? throw new \RuntimeException('The test requires atomic cache capability.');

    expect($atomic->setIfAbsent('claim', 'first', 1))->toBeTrue();
    usleep(2_000_000);

    expect($atomic->setIfAbsent('claim', 'second', 30))->toBeTrue()
        ->and($redisClusterFixture->cache->get('claim'))->toBe('second');
});


test('redis cluster leaves stale data for safe overwrite instead of deleting after read', function () use ($redisClusterFixture): void {
    expect($redisClusterFixture->cache->set('stale-race', 'value'))->toBeTrue();

    $physical = null;
    foreach ($redisClusterFixture->cluster->keys() as $key) {
        if (str_ends_with($key, ':d:stale-race')) {
            $physical = $key;
            break;
        }
    }
    expect($physical)->not->toBeNull();
    if (!is_string($physical)) {
        return;
    }

    $redisClusterFixture->cluster->set($physical, 'invalid-payload');

    expect($redisClusterFixture->cache->get('stale-race'))->toBeNull()
        ->and($redisClusterFixture->cluster->get($physical))->toBe('invalid-payload');
});

test('redis cluster generation repair fails closed without overwriting unexpected state', function () use ($redisClusterFixture): void {
    expect($redisClusterFixture->cache->set('generation-race', 'value'))->toBeTrue();

    $generation = null;
    foreach ($redisClusterFixture->cluster->keys() as $key) {
        if (str_ends_with($key, ':m:generation')) {
            $generation = $key;
            break;
        }
    }
    expect($generation)->not->toBeNull();
    if (!is_string($generation)) {
        return;
    }

    $redisClusterFixture->cluster->set($generation, 'malformed-generation');

    expect($redisClusterFixture->cache->get('generation-race'))->toBeNull()
        ->and($redisClusterFixture->cluster->get($generation))->toBe('malformed-generation');
});


test('redis cluster atomic consume discards deferred overlays without resurrection', function () use ($redisClusterFixture): void {
    $atomic = $redisClusterFixture->cache->atomic() ?? throw new \RuntimeException('The test requires atomic cache capability.');

    expect($redisClusterFixture->cache->set('deferred-consume', 'stored'))->toBeTrue()
        ->and($redisClusterFixture->cache->saveDeferred($redisClusterFixture->cache->getItem('deferred-consume')->set('pending')))->toBeTrue()
        ->and($atomic->getAndDelete('deferred-consume', 'missing'))->toBe('stored')
        ->and($atomic->getAndDelete('deferred-consume', 'missing'))->toBe('missing')
        ->and($redisClusterFixture->cache->commit())->toBeTrue()
        ->and($redisClusterFixture->cache->get('deferred-consume'))->toBeNull();
});
