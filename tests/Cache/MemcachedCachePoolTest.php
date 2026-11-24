<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Tests\Cache;

/**
 * tests/MemcachedCachePoolTest.php
 *
 * Runs only when the Memcached extension is loaded *and*
 * a Memcached daemon is reachable at 127.0.0.1:11211.
 */

use Infocyph\CacheLayer\Cache\Cache;
use Closure;
use DateInterval;
use DateTimeImmutable;
use InvalidArgumentException;
use Memcached;
use RuntimeException;
use Infocyph\CacheLayer\Cache\CacheOptions;
use Infocyph\CacheLayer\Cache\Item\CacheItem;
use Infocyph\CacheLayer\Cache\Lock\MemcachedLockProvider;
use Infocyph\CacheLayer\Exceptions\CacheInvalidArgumentException;
use Infocyph\CacheLayer\Support\MemcachedValueGuard;

/* ── Skip suite if Memcached unavailable ─────────────────────────── */

if (! class_exists(Memcached::class)) {
    throw new RuntimeException('Memcached extension is required for the configured cache test matrix.');
}

$memcachedHost = \Infocyph\CacheLayer\Tests\Support\CacheFixture::environment('IC_MEMCACHED_HOST') ?? \Infocyph\CacheLayer\Tests\Support\CacheFixture::environment('CACHELAYER_MEMCACHED_HOST') ?? '127.0.0.1';
$memcachedPort = (int) (\Infocyph\CacheLayer\Tests\Support\CacheFixture::environment('IC_MEMCACHED_PORT') ?? \Infocyph\CacheLayer\Tests\Support\CacheFixture::environment('CACHELAYER_MEMCACHED_PORT') ?? '11211');

$probe = new Memcached;
$probe->addServer($memcachedHost, $memcachedPort);
$probe->set('ping', 'pong');
if ($probe->getResultCode() !== Memcached::RES_SUCCESS) {
    throw new RuntimeException('Memcached service is required for the configured cache test matrix.');
}

/* ── Test bootstrap / teardown ───────────────────────────────────── */

$memcachedFixtureCache = Cache::nullStore();
$memcachedFixtureClient = new Memcached();

beforeEach(function () use ($memcachedHost, $memcachedPort, &$memcachedFixtureCache, &$memcachedFixtureClient): void {
    $client = new Memcached;
    $client->addServer($memcachedHost, $memcachedPort);
    $client->flush();                          // fresh slate

    $memcachedFixtureClient = $client;
    $memcachedFixtureCache = Cache::memcached(
        'tests',
        [[$memcachedHost, $memcachedPort, 0]],
        $client,
        new CacheOptions(allowClosures: true),
    );

});

afterEach(function () use (&$memcachedFixtureClient): void {
    $memcachedFixtureClient->flush();
});

/* ── Convenience helpers ────────────────────────────────────────── */

/* ─── convenience set()/get() ─────────────────────────────────── */
test('memcache set()/get()', function () use (&$memcachedFixtureCache): void {
    expect($memcachedFixtureCache->get('miss'))->toBeNull()
        ->and($memcachedFixtureCache->set('foo', 'bar'))->toBeTrue()
        ->and($memcachedFixtureCache->get('foo'))->toBe('bar');
});

/* ─── PSR-16 get($key, $default) ───────────────────────────────── */
test('get returns default when key missing (memcached)', function () use (&$memcachedFixtureCache): void {
    // Scalar
    expect($memcachedFixtureCache->get('nobody', 'dflt'))->toBe('dflt');

    $default = static fn(): string => 'hello';
    expect($memcachedFixtureCache->get('call', $default))->toBe($default)
        ->and($memcachedFixtureCache->has('call'))->toBeFalse();
});

test('get throws for invalid key (memcached)', function () use (&$memcachedFixtureCache): void {
    expect(fn (): mixed => $memcachedFixtureCache->get('bad key', 'x'))
        ->toThrow(CacheInvalidArgumentException::class);
});

/* ─── PSR-6 getItem()/save() ───────────────────────────────────── */
test('PSR-6 getItem()/save()', function () use (&$memcachedFixtureCache): void {
    $it = $memcachedFixtureCache->getItem('psr');
    assert($it instanceof CacheItem);
    expect($it)->toBeInstanceOf(CacheItem::class)
        ->and($it->isHit())->toBeFalse();

    $it->set(321)->save();
    expect($memcachedFixtureCache->getItem('psr')->get())->toBe(321);
});

test('saveDeferred() + commit()', function () use (&$memcachedFixtureCache): void {
    \Infocyph\CacheLayer\Tests\Support\CacheFixture::item($memcachedFixtureCache, 'a')->set('A')->saveDeferred();
    expect($memcachedFixtureCache->get('a'))->toBe('A');

    $memcachedFixtureCache->commit();
    expect($memcachedFixtureCache->get('a'))->toBe('A');
});

test('ArrayAccess is the only property-like access', function () use (&$memcachedFixtureCache): void {
    $memcachedFixtureCache['x'] = 7;
    expect($memcachedFixtureCache['x'])->toBe(7)
        ->and(method_exists($memcachedFixtureCache, '__get'))->toBeFalse();
});

test('TTL expiration', function () use (&$memcachedFixtureCache): void {
    \Infocyph\CacheLayer\Tests\Support\CacheFixture::item($memcachedFixtureCache, 'ttl')->set('x')->expiresAfter(1)->save();
    usleep(2_000_000);
    expect($memcachedFixtureCache->hasItem('ttl'))->toBeFalse();
});

test('closure round-trip', function () use (&$memcachedFixtureCache): void {
    $fn = fn (int $n): int => $n * 3;
    \Infocyph\CacheLayer\Tests\Support\CacheFixture::item($memcachedFixtureCache, 'cb')->set($fn)->save();
    $g = $memcachedFixtureCache->getItem('cb')->get();
    assert($g instanceof Closure);
    expect($g(3))->toBe(9);
});

test('invalid key throws', function () use (&$memcachedFixtureCache): void {
    expect(fn () => $memcachedFixtureCache->set('bad key', 'v'))
        ->toThrow(InvalidArgumentException::class);
});

test('clear only rotates this namespace generation', function () use ($memcachedHost, $memcachedPort, &$memcachedFixtureCache, &$memcachedFixtureClient): void {
    $other = Cache::memcached('other', [[$memcachedHost, $memcachedPort, 0]], $memcachedFixtureClient);
    $memcachedFixtureCache->set('z', 9);
    $other->set('z', 10);
    $memcachedFixtureCache->clear();
    expect($memcachedFixtureCache->hasItem('z'))->toBeFalse()
        ->and($other->get('z'))->toBe(10);
});

test('an expired Memcached lock owner cannot delete its replacement', function () use (&$memcachedFixtureClient): void {
    $provider = new MemcachedLockProvider($memcachedFixtureClient);
    $oldOwner = $provider->acquire('reports', 0.0, 30.0);
    expect($oldOwner)->not->toBeNull();
    if ($oldOwner === null) {
        return;
    }

    $memcachedFixtureClient->set($oldOwner->key, 'replacement-token', 30);
    $provider->release($oldOwner);

    expect($memcachedFixtureClient->get($oldOwner->key))->toBe('replacement-token');
});

test('Memcached adapter multiFetch()', function () use (&$memcachedFixtureCache): void {
    $memcachedFixtureCache->set('m1', 'foo');
    $memcachedFixtureCache->set('m2', 'bar');

    $items = iterator_to_array($memcachedFixtureCache->getItems(['m1', 'm2', 'missing']));

    expect($items['m1']->get())->toBe('foo')
        ->and($items['m2']->get())->toBe('bar')
        ->and($items['missing']->isHit())->toBeFalse();
});


test('Memcached long TTLs remain relative at the CacheLayer boundary', function () use (&$memcachedFixtureCache): void {
    $thirtyDays = 2_592_000;
    $thirtyDaysAndOne = $thirtyDays + 1;
    $thirtyOneDays = 2_678_400;

    expect($memcachedFixtureCache->set('ttl-30d', 'exact', $thirtyDays))->toBeTrue()
        ->and($memcachedFixtureCache->get('ttl-30d'))->toBe('exact')
        ->and($memcachedFixtureCache->set('ttl-30d-plus', 'plus', $thirtyDaysAndOne))->toBeTrue()
        ->and($memcachedFixtureCache->get('ttl-30d-plus'))->toBe('plus')
        ->and($memcachedFixtureCache->set('ttl-31d', 'month', $thirtyOneDays))->toBeTrue()
        ->and($memcachedFixtureCache->get('ttl-31d'))->toBe('month')
        ->and($memcachedFixtureCache->set('ttl-interval', 'interval', new DateInterval('P31D')))->toBeTrue()
        ->and($memcachedFixtureCache->get('ttl-interval'))->toBe('interval')
        ->and($memcachedFixtureCache->set(
            'ttl-absolute',
            'absolute',
            (new DateTimeImmutable())->modify('+31 days'),
        ))->toBeTrue()
        ->and($memcachedFixtureCache->get('ttl-absolute'))->toBe('absolute');
});

test('Memcached atomic writes and leases normalize long TTLs', function () use (&$memcachedFixtureCache, &$memcachedFixtureClient): void {
    $longTtl = 2_592_001;
    $atomic = $memcachedFixtureCache->atomic() ?? throw new \RuntimeException('The test requires atomic cache capability.');

    expect($atomic->setIfAbsent('atomic-long', 'first', $longTtl))->toBeTrue()
        ->and($memcachedFixtureCache->get('atomic-long'))->toBe('first')
        ->and($atomic->compareAndSet('atomic-long', 'first', 'second', $longTtl))->toBeTrue()
        ->and($memcachedFixtureCache->get('atomic-long'))->toBe('second');

    $provider = new MemcachedLockProvider($memcachedFixtureClient);
    $handle = $provider->acquire('long-lease', 0.0, (float) $longTtl);
    expect($handle)->not->toBeNull();
    if ($handle !== null) {
        expect($memcachedFixtureClient->get($handle->key))->toBe($handle->token)
            ->and($provider->refresh($handle, (float) $longTtl))->toBeTrue();
        $provider->release($handle);
    }
});


test('Memcached compare-safe guard preserves a concurrent replacement', function () use (&$memcachedFixtureClient): void {
    $key = 'tests:guard:race';

    $memcachedFixtureClient->set($key, 'fresh', 30);
    expect(MemcachedValueGuard::replaceIfUnchanged(
        $memcachedFixtureClient,
        $key,
        'stale',
        'repair',
        1,
    ))->toBe('fresh')
        ->and($memcachedFixtureClient->get($key))->toBe('fresh');

    $memcachedFixtureClient->set($key, 'stale', 30);
    expect(MemcachedValueGuard::replaceIfUnchanged(
        $memcachedFixtureClient,
        $key,
        'stale',
        'repair',
        1,
    ))->toBe('repair')
        ->and($memcachedFixtureClient->get($key))->toBe('repair');
});

test('Memcached delete reports backend errors but treats missing keys as success', function () use (&$memcachedFixtureCache): void {
    $unavailable = new Memcached;
    $adapter = new \Infocyph\CacheLayer\Cache\Adapter\MemcachedCacheAdapter(
        'unavailable',
        [],
        $unavailable,
    );

    expect($memcachedFixtureCache->delete('missing-delete'))->toBeTrue()
        ->and($memcachedFixtureCache->deleteItems(['missing-one', 'missing-two']))->toBeTrue()
        ->and($adapter->deleteItem('key'))->toBeFalse()
        ->and($adapter->deleteItems(['key']))->toBeFalse();
});
