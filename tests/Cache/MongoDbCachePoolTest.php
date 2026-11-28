<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Tests\Cache;

use Infocyph\CacheLayer\Cache\Adapter\MongoDbCacheAdapter;
use Infocyph\CacheLayer\Tests\Cache\Support\MongoCollectionDouble;
use Infocyph\CacheLayer\Cache\Cache;

$mongoFixture = new class(new MongoCollectionDouble(), Cache::nullStore()) {
    public function __construct(public MongoCollectionDouble $collection, public Cache $cache) {}
};

beforeEach(function () use ($mongoFixture): void {
    $mongoFixture->collection = new MongoCollectionDouble();

    $mongoFixture->cache = new Cache(new MongoDbCacheAdapter($mongoFixture->collection, 'mongo-tests'));
});

test('mongo adapter stores and retrieves values', function () use ($mongoFixture): void {
    $mongoFixture->cache->set('k', 'value');

    expect($mongoFixture->cache->get('k'))->toBe('value');
});

test('mongo adapter honors ttl', function () use ($mongoFixture): void {
    $mongoFixture->cache->set('ttl', 'v', 1);
    usleep(2_000_000);

    expect($mongoFixture->cache->get('ttl'))->toBeNull();
});

test('mongodb cache factory accepts injected collection', function () use ($mongoFixture): void {
    $cache = Cache::mongodb('mongo-tests', $mongoFixture->collection);
    $cache->set('f', 'ok');

    expect($cache->get('f'))->toBe('ok');
});

test('mongodb uses one native bulk write and one $in read', function () use ($mongoFixture): void {
    $mongoFixture->cache->setMultiple(['a' => 1, 'b' => 2, 'c' => 3]);
    $writes = $mongoFixture->collection->bulkWrites;
    $reads = $mongoFixture->collection->findCalls;

    expect($mongoFixture->cache->getMultiple(['c', 'a', 'missing']))
        ->toBe(['c' => 3, 'a' => 1, 'missing' => null])
        ->and($mongoFixture->collection->bulkWrites)->toBe($writes)
        ->and($mongoFixture->collection->findCalls)->toBe($reads + 1)
        ->and($writes)->toBeGreaterThanOrEqual(1);
});

test('mongodb exposes atomic cache capability with one-winner semantics', function () use ($mongoFixture): void {
    $atomic = $mongoFixture->cache->atomic() ?? throw new \RuntimeException('The test requires atomic cache capability.');

    expect($atomic->setIfAbsent('claim', 'first', 30))->toBeTrue()
        ->and($atomic->setIfAbsent('claim', 'second', 30))->toBeFalse()
        ->and($mongoFixture->cache->get('claim'))->toBe('first');
});

test('mongodb atomic compare-and-set uses strict live-value semantics', function () use ($mongoFixture): void {
    $atomic = $mongoFixture->cache->atomic() ?? throw new \RuntimeException('The test requires atomic cache capability.');
    $mongoFixture->cache->set('cas', 1, 30);

    expect($atomic->compareAndSet('cas', '1', 2, 30))->toBeFalse()
        ->and($atomic->compareAndSet('cas', 1, 2, 30))->toBeTrue()
        ->and($atomic->compareAndSet('cas', 1, 3, 30))->toBeFalse()
        ->and($mongoFixture->cache->get('cas'))->toBe(2);
});

test('mongodb atomic compare-and-set rejects tagged state', function () use ($mongoFixture): void {
    $atomic = $mongoFixture->cache->atomic() ?? throw new \RuntimeException('The test requires atomic cache capability.');
    $mongoFixture->cache->setTagged('tagged', 'v1', ['group'], 30);

    expect($atomic->compareAndSet('tagged', 'v1', 'v2', 30))->toBeFalse()
        ->and($mongoFixture->cache->get('tagged'))->toBe('v1');
});

test('mongodb atomic compare-and-set replacement honors ttl', function () use ($mongoFixture): void {
    $atomic = $mongoFixture->cache->atomic() ?? throw new \RuntimeException('The test requires atomic cache capability.');
    $mongoFixture->cache->set('cas-ttl', 'v1', 30);

    expect($atomic->compareAndSet('cas-ttl', 'v1', 'v2', 1))->toBeTrue();
    usleep(2_000_000);

    expect($mongoFixture->cache->get('cas-ttl'))->toBeNull();
});

test('mongodb atomic set replaces expired state', function () use ($mongoFixture): void {
    $atomic = $mongoFixture->cache->atomic() ?? throw new \RuntimeException('The test requires atomic cache capability.');
    $mongoFixture->cache->set('claim', 'old', 1);
    usleep(2_000_000);

    expect($atomic->setIfAbsent('claim', 'new', 30))->toBeTrue()
        ->and($mongoFixture->cache->get('claim'))->toBe('new');
});

test('mongodb atomic consume returns one live value', function () use ($mongoFixture): void {
    $atomic = $mongoFixture->cache->atomic() ?? throw new \RuntimeException('The test requires atomic cache capability.');
    $mongoFixture->cache->set('consume', ['ok' => true], 30);

    expect($atomic->getAndDelete('consume', 'missing'))->toBe(['ok' => true])
        ->and($atomic->getAndDelete('consume', 'missing'))->toBe('missing');
});

test('mongodb atomic set can reclaim tag-invalidated state', function () use ($mongoFixture): void {
    $atomic = $mongoFixture->cache->atomic() ?? throw new \RuntimeException('The test requires atomic cache capability.');
    $mongoFixture->cache->setTagged('claim', 'old', ['group'], 30);
    $mongoFixture->cache->invalidateTag('group');

    expect($atomic->setIfAbsent('claim', 'new', 30))->toBeTrue()
        ->and($mongoFixture->cache->get('claim'))->toBe('new');
});


test('mongodb atomic consume discards deferred overlays without resurrection', function () use ($mongoFixture): void {
    $atomic = $mongoFixture->cache->atomic() ?? throw new \RuntimeException('The test requires atomic cache capability.');

    expect($mongoFixture->cache->set('deferred-consume', 'stored'))->toBeTrue()
        ->and($mongoFixture->cache->saveDeferred($mongoFixture->cache->getItem('deferred-consume')->set('pending')))->toBeTrue()
        ->and($atomic->getAndDelete('deferred-consume', 'missing'))->toBe('stored')
        ->and($atomic->getAndDelete('deferred-consume', 'missing'))->toBe('missing')
        ->and($mongoFixture->cache->commit())->toBeTrue()
        ->and($mongoFixture->cache->get('deferred-consume'))->toBeNull();
});

test('mongo adapter accepts associative PSR-6 delete key arrays', function () use ($mongoFixture): void {
    $adapter = new MongoDbCacheAdapter($mongoFixture->collection, 'mongo-associative');
    $cache = new Cache($adapter);
    expect($cache->setMultiple(['a' => 1, 'b' => 2, 'c' => 3]))->toBeTrue()
        ->and($adapter->deleteItems(['first' => 'a', 'second' => 'b']))->toBeTrue()
        ->and($cache->has('a'))->toBeFalse()
        ->and($cache->has('b'))->toBeFalse()
        ->and($cache->get('c'))->toBe(3);
});


test('MongoDB atomic writes do not report unacknowledged outcomes as success', function (): void {
    $collection = new MongoCollectionDouble();
    $cache = new Cache(new MongoDbCacheAdapter($collection, 'mongo-unacknowledged'), options: new \Infocyph\CacheLayer\Cache\CacheOptions(failOpen: false));
    $atomic = $cache->atomic() ?? throw new \RuntimeException('Atomic capability required.');
    expect($atomic->setIfAbsent('existing', 1))->toBeTrue();
    $collection->acknowledged = false;
    expect(fn (): bool => $atomic->setIfAbsent('claim', 1))->toThrow(\Infocyph\CacheLayer\Exceptions\CacheBackendException::class)
        ->and(fn (): bool => $atomic->compareAndSet('existing', 1, 2))->toThrow(\Infocyph\CacheLayer\Exceptions\CacheBackendException::class);
});
