<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cache\AtomicCacheInterface;
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

beforeEach(function () use ($valkeyHost, $valkeyPort, $valkeyPassword) {
    $client = new Redis;
    $client->connect($valkeyHost, $valkeyPort);
    if ($valkeyPassword !== '') {
        $client->auth($valkeyPassword);
    }
    $client->flushDB();

    $this->valkeyClient = $client;
    $this->cache = Cache::valkey(
        'valkey-tests',
        sprintf('valkey://%s:%d', $valkeyHost, $valkeyPort),
        $client,
    );
});

afterEach(function () {
    $this->cache->clear();
});

test('valkey adapter stores and retrieves values', function () {
    expect($this->cache->set('foo', 'bar'))->toBeTrue()
        ->and($this->cache->get('foo'))->toBe('bar');
});

test('valkey adapter supports remember lock path', function () {
    $runs = 0;

    $v1 = $this->cache->remember('once', function () use (&$runs) {
        ++$runs;

        return 'value';
    }, 30);
    $v2 = $this->cache->remember('once', fn () => 'new-value');

    expect($v1)->toBe('value')
        ->and($v2)->toBe('value')
        ->and($runs)->toBe(1);
});

test('valkey exposes Redis-compatible atomic capability', function () {
    $atomic = $this->cache->atomic();

    expect($atomic)->toBeInstanceOf(AtomicCacheInterface::class)
        ->and($atomic->setIfAbsent('claim', 'first', 30))->toBeTrue()
        ->and($atomic->setIfAbsent('claim', 'second', 30))->toBeFalse()
        ->and($atomic->getAndDelete('claim', 'missing'))->toBe('first')
        ->and($atomic->getAndDelete('claim', 'missing'))->toBe('missing');
});

test('valkey atomic compare-and-set is strict and rejects tagged state', function () {
    $atomic = $this->cache->atomic();
    expect($atomic)->not->toBeNull();
    $this->cache->set('cas', 1, 30);

    expect($atomic->compareAndSet('cas', '1', 2, 30))->toBeFalse()
        ->and($atomic->compareAndSet('cas', 1, 2, 30))->toBeTrue()
        ->and($atomic->compareAndSet('cas', 1, 3, 30))->toBeFalse()
        ->and($this->cache->get('cas'))->toBe(2);

    $this->cache->setTagged('tagged', 'v1', ['group'], 30);

    expect($atomic->compareAndSet('tagged', 'v1', 'v2', 30))->toBeFalse()
        ->and($this->cache->get('tagged'))->toBe('v1');
});

test('valkey atomic compare-and-set replacement honors ttl', function () {
    $atomic = $this->cache->atomic();
    expect($atomic)->not->toBeNull();
    $this->cache->set('cas-ttl', 'v1', 30);

    expect($atomic->compareAndSet('cas-ttl', 'v1', 'v2', 1))->toBeTrue();
    usleep(2_000_000);

    expect($this->cache->get('cas-ttl'))->toBeNull();
});

test('valkey atomic ttl permits a later claim', function () {
    $atomic = $this->cache->atomic();
    expect($atomic)->not->toBeNull()
        ->and($atomic->setIfAbsent('claim', 'first', 1))->toBeTrue();
    usleep(2_000_000);

    expect($atomic->setIfAbsent('claim', 'second', 30))->toBeTrue()
        ->and($this->cache->get('claim'))->toBe('second');
});


test('Valkey atomic counters stay isolated from cache clear and preserve exact integers', function () {
    $counters = AtomicCounters::valkey('valkey-tests', client: $this->valkeyClient);
    $large = 9_007_199_254_740_993;
    $first = $counters->increment('window', $large, 30);
    $physical = 'cachelayer:counter:valkey-tests:window';
    $ttlBefore = $this->valkeyClient->ttl($physical);
    $later = $counters->decrement('window', 2, 30);
    $ttlAfter = $this->valkeyClient->ttl($physical);

    expect($first->value)->toBe($large)
        ->and($first->initialized)->toBeTrue()
        ->and($later->value)->toBe($large - 2)
        ->and($later->initialized)->toBeFalse()
        ->and($ttlAfter)->toBeGreaterThan(0)
        ->and($ttlAfter)->toBeLessThanOrEqual($ttlBefore)
        ->and($this->cache->set('ordinary', 'value'))->toBeTrue()
        ->and($this->cache->clear())->toBeTrue()
        ->and($counters->get('window'))->toBe($large - 2);

    $this->valkeyClient->set('cachelayer:counter:valkey-tests:invalid', '9223372036854775808');
    expect(fn () => $counters->get('invalid'))->toThrow(AtomicCounterException::class);
});

test('Valkey atomic counter initialization has exactly one winner under contention', function () use ($valkeyHost, $valkeyPort, $valkeyPassword) {
    $counters = AtomicCounters::valkey('valkey-tests', client: $this->valkeyClient);
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


test('Valkey atomic counters expire fixed windows', function () {
    $counters = AtomicCounters::valkey('valkey-tests', client: $this->valkeyClient);

    expect($counters->increment('short-window', 1, 1)->initialized)->toBeTrue()
        ->and($counters->get('short-window'))->toBe(1);
    usleep(2_000_000);

    expect($counters->get('short-window'))->toBeNull();
});
