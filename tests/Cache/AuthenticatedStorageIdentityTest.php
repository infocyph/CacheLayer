<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cache\Adapter\ArrayCacheAdapter;
use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\CacheLayer\Cache\CacheOptions;

test('signed payloads are bound to their logical cache key', function () {
    $adapter = new ArrayCacheAdapter('tenant');
    $cache = new Cache(
        $adapter,
        options: new CacheOptions(integrityKey: 'batch-two-secret'),
        namespace: 'tenant',
    );
    expect($cache->set('alice', ['role' => 'admin']))->toBeTrue();

    $store = new ReflectionProperty($adapter, 'store');
    $records = $store->getValue($adapter);
    $records['tenant:d:bob'] = $records['tenant:d:alice'];
    $store->setValue($adapter, $records);

    expect($cache->get('bob', 'missing'))->toBe('missing')
        ->and($adapter->hasItem('bob'))->toBeFalse();
});

test('signed payloads are bound to their logical cache namespace', function () {
    $options = new CacheOptions(integrityKey: 'batch-two-secret');
    $sourceAdapter = new ArrayCacheAdapter('tenant-a');
    $targetAdapter = new ArrayCacheAdapter('tenant-b');
    $source = new Cache($sourceAdapter, options: $options, namespace: 'tenant-a');
    $target = new Cache($targetAdapter, options: $options, namespace: 'tenant-b');

    expect($source->set('same', 'source'))->toBeTrue();

    $sourceStore = new ReflectionProperty($sourceAdapter, 'store');
    $targetStore = new ReflectionProperty($targetAdapter, 'store');
    $records = $targetStore->getValue($targetAdapter);
    $records['tenant-b:d:same'] = $sourceStore->getValue($sourceAdapter)['tenant-a:d:same'];
    $targetStore->setValue($targetAdapter, $records);

    expect($target->get('same', 'missing'))->toBe('missing')
        ->and($targetAdapter->hasItem('same'))->toBeFalse();
});

test('legacy unbound signed payloads do not satisfy bound integrity', function () {
    $adapter = new ArrayCacheAdapter('tenant');
    $cache = new Cache(
        $adapter,
        options: new CacheOptions(integrityKey: 'batch-two-secret'),
        namespace: 'tenant',
    );

    $serialized = serialize([
        'format' => 2,
        'encoding' => 'native',
        'value' => 'legacy',
        'expires' => null,
        'tags' => [],
        'namespace' => null,
    ]);
    $plain = 'cl2:' . $serialized;
    $legacy = 'cl2-sig:' . hash_hmac('sha256', $plain, 'batch-two-secret') . ':' . $plain;

    $store = new ReflectionProperty($adapter, 'store');
    $store->setValue($adapter, ['tenant:d:legacy' => $legacy]);

    expect($cache->get('legacy', 'missing'))->toBe('missing')
        ->and($adapter->hasItem('legacy'))->toBeFalse();
});

test('signed tier promotion preserves the logical payload identity', function () {
    $l1 = new ArrayCacheAdapter('fast-tier');
    $l2 = new ArrayCacheAdapter('slow-tier');
    $cache = Cache::tiered(
        [$l1, $l2],
        options: new CacheOptions(integrityKey: 'batch-two-secret'),
        namespace: 'logical-store',
    );

    expect($cache->set('record', 'value'))->toBeTrue()
        ->and($l1->clear())->toBeTrue()
        ->and($cache->get('record'))->toBe('value')
        ->and($l1->getItem('record')->isHit())->toBeTrue()
        ->and($cache->get('record'))->toBe('value');
});

test('object and closure deserialization require explicit opt-in by default', function () {
    $default = Cache::memory('secure-default');
    $closure = static fn(): string => 'closure';

    expect($default->set('object', new stdClass()))->toBeFalse()
        ->and($default->set('closure', $closure))->toBeFalse();

    $explicit = Cache::memory(
        'explicit-serialization',
        new CacheOptions(allowClosures: true, allowObjects: true),
    );

    expect($explicit->set('object', new stdClass()))->toBeTrue()
        ->and($explicit->get('object'))->toBeInstanceOf(stdClass::class)
        ->and($explicit->set('closure', $closure))->toBeTrue()
        ->and($explicit->get('closure'))->toBeInstanceOf(Closure::class);
});
