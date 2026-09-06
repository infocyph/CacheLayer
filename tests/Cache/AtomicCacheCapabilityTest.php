<?php

declare(strict_types=1);

use DateTimeImmutable;
use Infocyph\CacheLayer\Cache\Adapter\ArrayCacheAdapter;
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

test('unsupported and composite stores do not advertise atomic capability', function () {
    $sqliteFile = sys_get_temp_dir() . '/cachelayer-atomic-' . uniqid('', true) . '.sqlite';
    $sqlite = Cache::sqlite('atomic-sqlite', $sqliteFile);
    $tiered = Cache::tiered([
        new ArrayCacheAdapter('atomic-tier-l1'),
        new ArrayCacheAdapter('atomic-tier-l2'),
    ], namespace: 'atomic-tiered');

    try {
        expect($sqlite->atomic())->toBeNull()
            ->and($tiered->atomic())->toBeNull();
    } finally {
        unset($sqlite);
        if (is_file($sqliteFile)) {
            unlink($sqliteFile);
        }
    }
});

test('set if absent has one-winner semantics and preserves existing values', function () {
    $cache = Cache::memory('atomic-set-if-absent');
    $atomic = $cache->atomic();

    expect($atomic)->not->toBeNull()
        ->and($atomic->setIfAbsent('claim', 'first', 30))->toBeTrue()
        ->and($atomic->setIfAbsent('claim', 'second', 30))->toBeFalse()
        ->and($cache->get('claim'))->toBe('first');
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

test('compare and set uses strict live-value semantics', function () {
    $cache = Cache::memory('atomic-cas');
    $atomic = $cache->atomic();

    expect($atomic)->not->toBeNull();
    $cache->set('version', 1);

    expect($atomic->compareAndSet('version', '1', 2))->toBeFalse()
        ->and($cache->get('version'))->toBe(1)
        ->and($atomic->compareAndSet('version', 1, 2))->toBeTrue()
        ->and($cache->get('version'))->toBe(2)
        ->and($atomic->compareAndSet('version', 1, 3))->toBeFalse()
        ->and($cache->get('version'))->toBe(2);
});

test('compare and set distinguishes absence from cached null', function () {
    $cache = Cache::memory('atomic-cas-null');
    $atomic = $cache->atomic();

    expect($atomic)->not->toBeNull();
    $cache->set('nullable', null);

    expect($atomic->compareAndSet('missing', null, 'created'))->toBeFalse()
        ->and($cache->has('missing'))->toBeFalse()
        ->and($atomic->compareAndSet('nullable', null, 'replaced'))->toBeTrue()
        ->and($cache->get('nullable'))->toBe('replaced');
});

test('compare and set applies replacement ttl and accepts absolute expiry', function () {
    $cache = Cache::memory('atomic-cas-ttl');
    $atomic = $cache->atomic();

    expect($atomic)->not->toBeNull();
    $cache->set('claim', 'old');

    expect($atomic->compareAndSet('claim', 'old', 'new', new DateTimeImmutable('+1 second')))->toBeTrue()
        ->and($cache->get('claim'))->toBe('new');

    usleep(2_000_000);

    expect($cache->has('claim'))->toBeFalse();
});

test('non-positive ttl is a no-op for compare and set', function () {
    $cache = Cache::memory('atomic-cas-zero-ttl');
    $atomic = $cache->atomic();

    expect($atomic)->not->toBeNull();
    $cache->set('claim', 'old');

    expect($atomic->compareAndSet('claim', 'old', 'new', 0))->toBeFalse()
        ->and($cache->get('claim'))->toBe('old');
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

test('atomic operations reject stale tagged entries as live state', function () {
    $cache = Cache::memory('atomic-tagged');
    $atomic = $cache->atomic();

    expect($atomic)->not->toBeNull();
    $cache->setTagged('claim', 'old', ['group']);
    $cache->invalidateTag('group');

    expect($atomic->compareAndSet('claim', 'old', 'cas-new', 30))->toBeFalse()
        ->and($atomic->setIfAbsent('claim', 'new', 30))->toBeTrue()
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
        ->and($atomic->compareAndSet('claim', 'value', 'updated'))->toBeTrue()
        ->and($atomic->getAndDelete('claim'))->toBe('updated');

    $snapshot = $cache->exportMetrics();

    expect($snapshot['array']['atomic_set_if_absent'] ?? 0)->toBe(1)
        ->and($snapshot['array']['atomic_set_if_absent_success'] ?? 0)->toBe(1)
        ->and($snapshot['array']['atomic_compare_and_set'] ?? 0)->toBe(1)
        ->and($snapshot['array']['atomic_compare_and_set_success'] ?? 0)->toBe(1)
        ->and($snapshot['array']['atomic_get_and_delete'] ?? 0)->toBe(1)
        ->and($snapshot['array']['atomic_get_and_delete_hit'] ?? 0)->toBe(1);
});
