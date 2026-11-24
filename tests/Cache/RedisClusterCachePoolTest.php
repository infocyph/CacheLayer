<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Tests\Cache;

use Infocyph\CacheLayer\Tests\Cache\Support\RedisClusterDouble;
use Infocyph\CacheLayer\Cache\Cache;

$redisClusterFixture = new class(new RedisClusterDouble(), Cache::nullStore()) {
    public function __construct(public RedisClusterDouble $cluster, public Cache $cache) {}
};

beforeEach(function () use ($redisClusterFixture): void {
    $redisClusterFixture->cluster = new RedisClusterDouble();

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
    $physical ??= throw new \RuntimeException('Expected the physical Redis cluster record.');

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
    $generation ??= throw new \RuntimeException('Expected the Redis cluster generation record.');

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
