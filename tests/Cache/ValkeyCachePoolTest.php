<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Tests\Cache;

use Infocyph\CacheLayer\Cache\AtomicCacheInterface;
use Redis;
use RuntimeException;
use Throwable;
use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\CacheLayer\Counter\AtomicCounters;
use Infocyph\CacheLayer\Counter\Exception\AtomicCounterException;
use Infocyph\CacheLayer\Tests\Support\AtomicCounterProcessProbe;

if (! class_exists(Redis::class)) {
    throw new RuntimeException('phpredis is required for the configured Valkey test matrix.');
}

$valkeyHost = getenv('IC_VALKEY_HOST') ?: getenv('CACHELAYER_VALKEY_HOST') ?: getenv('IC_REDIS_HOST') ?: getenv('CACHELAYER_REDIS_HOST') ?: '127.0.0.1';
$valkeyPort = (int) (getenv('IC_VALKEY_PORT') ?: getenv('CACHELAYER_VALKEY_PORT') ?: getenv('IC_REDIS_PORT') ?: getenv('CACHELAYER_REDIS_PORT') ?: '6379');
$valkeyPassword = getenv('IC_VALKEY_PASSWORD') ?: getenv('CACHELAYER_VALKEY_PASSWORD') ?: getenv('IC_REDIS_PASSWORD') ?: getenv('CACHELAYER_REDIS_PASSWORD') ?: '';

try {
    $probe = new Redis;
    $probe->connect($valkeyHost, $valkeyPort, 0.5);
    if ($valkeyPassword !== '') {
        $probe->auth($valkeyPassword);
    }
    $probe->ping();
} catch (Throwable $failure) {
    throw new RuntimeException('Valkey service is required for the configured cache test matrix.', 0, $failure);
}

$valkeyFixtureCache = Cache::nullStore();
$valkeyFixtureValkeyClient = new Redis();

beforeEach(function () use ($valkeyHost, $valkeyPort, $valkeyPassword, &$valkeyFixtureCache, &$valkeyFixtureValkeyClient): void {
    $client = new Redis;
    $client->connect($valkeyHost, $valkeyPort);
    if ($valkeyPassword !== '') {
        $client->auth($valkeyPassword);
    }
    $client->flushDB();

    $valkeyFixtureValkeyClient = $client;
    $valkeyFixtureCache = Cache::valkey(
        'valkey-tests',
        sprintf('valkey://%s:%d', $valkeyHost, $valkeyPort),
        $client,
    );
});

afterEach(function () use (&$valkeyFixtureCache): void {
    $valkeyFixtureCache->clear();
});

test('valkey adapter stores and retrieves values', function () use (&$valkeyFixtureCache): void {
    expect($valkeyFixtureCache->set('foo', 'bar'))->toBeTrue()
        ->and($valkeyFixtureCache->get('foo'))->toBe('bar');
});

test('valkey adapter supports remember lock path', function () use (&$valkeyFixtureCache): void {
    $runs = 0;

    $v1 = $valkeyFixtureCache->remember('once', function () use (&$runs): string {
        ++$runs;

        return 'value';
    }, 30);
    $v2 = $valkeyFixtureCache->remember('once', fn (): string => 'new-value');

    expect($v1)->toBe('value')
        ->and($v2)->toBe('value')
        ->and($runs)->toBe(1);
});

test('valkey exposes Redis-compatible atomic capability', function () use (&$valkeyFixtureCache): void {
    $atomic = $valkeyFixtureCache->atomic();

    expect($atomic)->toBeInstanceOf(AtomicCacheInterface::class)
        ->and($atomic->setIfAbsent('claim', 'first', 30))->toBeTrue()
        ->and($atomic->setIfAbsent('claim', 'second', 30))->toBeFalse()
        ->and($atomic->getAndDelete('claim', 'missing'))->toBe('first')
        ->and($atomic->getAndDelete('claim', 'missing'))->toBe('missing');
});

test('valkey atomic compare-and-set is strict and rejects tagged state', function () use (&$valkeyFixtureCache): void {
    $atomic = $valkeyFixtureCache->atomic();
    expect($atomic)->not->toBeNull();
    $valkeyFixtureCache->set('cas', 1, 30);

    expect($atomic->compareAndSet('cas', '1', 2, 30))->toBeFalse()
        ->and($atomic->compareAndSet('cas', 1, 2, 30))->toBeTrue()
        ->and($atomic->compareAndSet('cas', 1, 3, 30))->toBeFalse()
        ->and($valkeyFixtureCache->get('cas'))->toBe(2);

    $valkeyFixtureCache->setTagged('tagged', 'v1', ['group'], 30);

    expect($atomic->compareAndSet('tagged', 'v1', 'v2', 30))->toBeFalse()
        ->and($valkeyFixtureCache->get('tagged'))->toBe('v1');
});

test('valkey atomic compare-and-set replacement honors ttl', function () use (&$valkeyFixtureCache): void {
    $atomic = $valkeyFixtureCache->atomic();
    expect($atomic)->not->toBeNull();
    $valkeyFixtureCache->set('cas-ttl', 'v1', 30);

    expect($atomic->compareAndSet('cas-ttl', 'v1', 'v2', 1))->toBeTrue();
    usleep(2_000_000);

    expect($valkeyFixtureCache->get('cas-ttl'))->toBeNull();
});

test('valkey atomic ttl permits a later claim', function () use (&$valkeyFixtureCache): void {
    $atomic = $valkeyFixtureCache->atomic();
    expect($atomic)->not->toBeNull()
        ->and($atomic->setIfAbsent('claim', 'first', 1))->toBeTrue();
    usleep(2_000_000);

    expect($atomic->setIfAbsent('claim', 'second', 30))->toBeTrue()
        ->and($valkeyFixtureCache->get('claim'))->toBe('second');
});


test('Valkey atomic counters stay isolated from cache clear and preserve exact integers', function () use (&$valkeyFixtureCache, &$valkeyFixtureValkeyClient): void {
    $counters = AtomicCounters::valkey('valkey-tests', client: $valkeyFixtureValkeyClient);
    $large = 9_007_199_254_740_993;
    $first = $counters->increment('window', $large, 30);
    $physical = 'cachelayer:counter:valkey-tests:window';
    $ttlBefore = $valkeyFixtureValkeyClient->ttl($physical);
    $later = $counters->decrement('window', 2, 30);
    $ttlAfter = $valkeyFixtureValkeyClient->ttl($physical);

    expect($first->value)->toBe($large)
        ->and($first->initialized)->toBeTrue()
        ->and($later->value)->toBe($large - 2)
        ->and($later->initialized)->toBeFalse()
        ->and($ttlAfter)->toBeGreaterThan(0)
        ->and($ttlAfter)->toBeLessThanOrEqual($ttlBefore)
        ->and($valkeyFixtureCache->set('ordinary', 'value'))->toBeTrue()
        ->and($valkeyFixtureCache->clear())->toBeTrue()
        ->and($counters->get('window'))->toBe($large - 2);

    $valkeyFixtureValkeyClient->set('cachelayer:counter:valkey-tests:invalid', '9223372036854775808');
    expect(fn () => $counters->get('invalid'))->toThrow(AtomicCounterException::class);
});

test('Valkey atomic counter initialization has exactly one winner under contention', function () use ($valkeyHost, $valkeyPort, $valkeyPassword, &$valkeyFixtureValkeyClient): void {
    $counters = AtomicCounters::valkey('valkey-tests', client: $valkeyFixtureValkeyClient);
    $wins = AtomicCounterProcessProbe::initializedWinners(
        'valkey',
        $valkeyHost,
        $valkeyPort,
        $valkeyPassword,
        'valkey-tests',
        'contended-counter',
    );

    expect($wins)->toBe(1)
        ->and($counters->get('contended-counter'))->toBe(8);
});


test('Valkey atomic counters expire fixed windows', function () use (&$valkeyFixtureValkeyClient): void {
    $counters = AtomicCounters::valkey('valkey-tests', client: $valkeyFixtureValkeyClient);

    expect($counters->increment('short-window', 1, 1)->initialized)->toBeTrue()
        ->and($counters->get('short-window'))->toBe(1);
    usleep(2_000_000);

    expect($counters->get('short-window'))->toBeNull();
});


test('Valkey atomic consume discards deferred overlays without resurrection', function () use (&$valkeyFixtureCache): void {
    $atomic = $valkeyFixtureCache->atomic();
    expect($atomic)->not->toBeNull()
        ->and($valkeyFixtureCache->set('deferred-consume', 'stored'))->toBeTrue()
        ->and($valkeyFixtureCache->saveDeferred($valkeyFixtureCache->getItem('deferred-consume')->set('pending')))->toBeTrue()
        ->and($atomic->getAndDelete('deferred-consume', 'missing'))->toBe('stored')
        ->and($atomic->getAndDelete('deferred-consume', 'missing'))->toBe('missing')
        ->and($valkeyFixtureCache->commit())->toBeTrue()
        ->and($valkeyFixtureCache->get('deferred-consume'))->toBeNull();
});


test('Valkey clear in boundary namespace preserves counter and lock domains', function () use (&$valkeyFixtureValkeyClient): void {
    $cache = Cache::valkey('cachelayer', client: $valkeyFixtureValkeyClient);
    $counters = AtomicCounters::valkey('audit-counter', client: $valkeyFixtureValkeyClient);
    $locks = new \Infocyph\CacheLayer\Cache\Lock\RedisLockProvider($valkeyFixtureValkeyClient);

    expect($cache->set('ordinary', 'value'))->toBeTrue()
        ->and($counters->increment('window', 5, 30)->value)->toBe(5);

    $held = $locks->acquire('boundary-lock', 0.0, 30.0);
    expect($held)->not->toBeNull();

    expect($cache->clear())->toBeTrue()
        ->and($cache->get('ordinary'))->toBeNull()
        ->and($counters->get('window'))->toBe(5)
        ->and($locks->acquire('boundary-lock', 0.0, 30.0))->toBeNull();

    $locks->release($held);
});
