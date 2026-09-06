<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cache\AtomicCacheInterface;
use Infocyph\CacheLayer\Cache\Cache;

if (! class_exists(Redis::class)) {
    test('phpredis ext not loaded - skipping valkey tests')->skip();

    return;
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
} catch (Throwable) {
    test('Valkey server unreachable - skipping')->skip();

    return;
}

beforeEach(function () use ($valkeyHost, $valkeyPort, $valkeyPassword) {
    $client = new Redis;
    $client->connect($valkeyHost, $valkeyPort);
    if ($valkeyPassword !== '') {
        $client->auth($valkeyPassword);
    }
    $client->flushDB();

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

test('valkey atomic ttl permits a later claim', function () {
    $atomic = $this->cache->atomic();
    expect($atomic)->not->toBeNull()
        ->and($atomic->setIfAbsent('claim', 'first', 1))->toBeTrue();
    usleep(2_000_000);

    expect($atomic->setIfAbsent('claim', 'second', 30))->toBeTrue()
        ->and($this->cache->get('claim'))->toBe('second');
});
