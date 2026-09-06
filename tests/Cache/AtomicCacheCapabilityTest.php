<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cache\AtomicCacheInterface;
use Infocyph\CacheLayer\Cache\AtomicCacheProviderInterface;
use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\CacheLayer\Cache\CacheOptions;
use Infocyph\CacheLayer\Cache\Metrics\InMemoryCacheMetricsCollector;

test('cache exposes atomic capability only when the adapter guarantees it', function () {
    $memory = Cache::memory('atomic-memory');
    $null = Cache::nullStore();

    expect($memory)->toBeInstanceOf(AtomicCacheProviderInterface::class)
        ->and($memory->atomic())->toBeInstanceOf(AtomicCacheInterface::class)
        ->and($null->atomic())->toBeNull();
});

test('set if absent has one-winner semantics and preserves existing values', function () {
    $cache = Cache::memory('atomic-set-if-absent');
    $atomic = $cache->atomic();

    expect($atomic)->not->toBeNull()
        ->and($atomic->setIfAbsent('claim', 'first', 30))->toBeTrue()
        ->and($atomic->setIfAbsent('claim', 'second', 30))->toBeFalse()
        ->and($cache->get('claim'))->toBe('first');
});

test('set if absent treats expired entries as absent', function () {
    $cache = Cache::memory('atomic-expired');
    $atomic = $cache->atomic();

    expect($atomic)->not->toBeNull();
    $cache->set('claim', 'old', 1);
    sleep(2);

    expect($atomic->setIfAbsent('claim', 'new', 30))->toBeTrue()
        ->and($cache->get('claim'))->toBe('new');
});

test('non-positive ttl is a no-op for set if absent', function () {
    $cache = Cache::memory('atomic-zero-ttl');
    $atomic = $cache->atomic();

    expect($atomic)->not->toBeNull()
        ->and($atomic->setIfAbsent('claim', 'value', 0))->toBeFalse()
        ->and($cache->has('claim'))->toBeFalse();

    $cache->set('existing', 'keep');
    expect($atomic->setIfAbsent('existing', 'replace', 0))->toBeFalse()
        ->and($cache->get('existing'))->toBe('keep');
});

test('get and delete consumes exactly one live value including cached null', function () {
    $cache = Cache::memory('atomic-consume');
    $atomic = $cache->atomic();

    expect($atomic)->not->toBeNull();
    $cache->set('nullable', null);

    expect($atomic->getAndDelete('nullable', 'fallback'))->toBeNull()
        ->and($atomic->getAndDelete('nullable', 'fallback'))->toBe('fallback')
        ->and($cache->has('nullable'))->toBeFalse();
});

test('compare and set uses strict value comparison', function () {
    $cache = Cache::memory('atomic-cas');
    $atomic = $cache->atomic();

    expect($atomic)->not->toBeNull();
    $cache->set('version', 1);

    expect($atomic->compareAndSet('version', '1', 2))->toBeFalse()
        ->and($cache->get('version'))->toBe(1)
        ->and($atomic->compareAndSet('version', 1, 2))->toBeTrue()
        ->and($cache->get('version'))->toBe(2);
});

test('compare and set with non-positive ttl atomically deletes a matching value', function () {
    $cache = Cache::memory('atomic-cas-delete');
    $atomic = $cache->atomic();

    expect($atomic)->not->toBeNull();
    $cache->set('version', 'current');

    expect($atomic->compareAndSet('version', 'stale', 'ignored', 0))->toBeFalse()
        ->and($cache->get('version'))->toBe('current')
        ->and($atomic->compareAndSet('version', 'current', 'ignored', 0))->toBeTrue()
        ->and($cache->has('version'))->toBeFalse();
});

test('atomic operations reject stale tagged entries as live state', function () {
    $cache = Cache::memory('atomic-tagged');
    $atomic = $cache->atomic();

    expect($atomic)->not->toBeNull();
    $cache->setTagged('claim', 'old', ['group']);
    $cache->invalidateTag('group');

    expect($atomic->setIfAbsent('claim', 'new', 30))->toBeTrue()
        ->and($cache->get('claim'))->toBe('new');
});

test('atomic capability follows cache fail-open and metrics policy', function () {
    $metrics = new InMemoryCacheMetricsCollector();
    $cache = Cache::memory(
        'atomic-policy',
        new CacheOptions(failOpen: false),
    )->setMetricsCollector($metrics);
    $atomic = $cache->atomic();

    expect($atomic)->not->toBeNull()
        ->and($atomic->setIfAbsent('claim', 'value'))->toBeTrue()
        ->and($atomic->getAndDelete('claim'))->toBe('value');

    $snapshot = $cache->exportMetrics();
    $adapter = Infocyph\CacheLayer\Cache\Adapter\ArrayCacheAdapter::class;

    expect($snapshot[$adapter]['atomic_set_if_absent'] ?? 0)->toBe(1)
        ->and($snapshot[$adapter]['atomic_set_if_absent_success'] ?? 0)->toBe(1)
        ->and($snapshot[$adapter]['atomic_get_and_delete'] ?? 0)->toBe(1)
        ->and($snapshot[$adapter]['atomic_get_and_delete_hit'] ?? 0)->toBe(1);
});
