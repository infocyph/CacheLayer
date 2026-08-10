<?php

declare(strict_types=1);

/**
 * tests/RedisCachePoolTest.php
 *
 * Executes the same behavioural checks as the File/APCu/Memcache/SQLite
 * suites, but against the Redis adapter.  The suite self-skips when:
 *   • phpredis extension is not loaded, or
 *   • no Redis server answers at 127.0.0.1:6379.
 */

use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\CacheLayer\Cache\Item\CacheItem;
use Infocyph\CacheLayer\Exceptions\CacheInvalidArgumentException;

/* ── skip whole file when Redis unavailable ───────────────────────── */
if (! class_exists(Redis::class)) {
    test('phpredis ext not loaded – skipping')->skip();

    return;
}

$redisHost = getenv('IC_REDIS_HOST') ?: getenv('CACHELAYER_REDIS_HOST') ?: '127.0.0.1';
$redisPort = (int) (getenv('IC_REDIS_PORT') ?: getenv('CACHELAYER_REDIS_PORT') ?: '6379');
$redisPassword = getenv('IC_REDIS_PASSWORD');
if ($redisPassword === false) {
    $redisPassword = getenv('IC_SERVICE_PASSWORD');
}
if ($redisPassword === false) {
    $redisPassword = getenv('CACHELAYER_REDIS_PASSWORD');
}
if ($redisPassword === false) {
    $redisPassword = '';
}

try {
    $probe = new Redis;
    $probe->connect($redisHost, $redisPort, 0.5);
    if ($redisPassword !== '') {
        $probe->auth($redisPassword);
    }
    $probe->ping();
} catch (Throwable) {
    test('Redis server unreachable – skipping')->skip();

    return;
}

/* ── bootstrap / teardown ────────────────────────────────────────── */
beforeEach(function () use ($redisHost, $redisPort, $redisPassword) {
    $client = new Redis;
    $client->connect($redisHost, $redisPort);
    if ($redisPassword !== '') {
        $client->auth($redisPassword);
    }
    $client->flushDB();                               // fresh DB 0

    $this->cache = Cache::redis(
        'tests',
        sprintf('redis://%s:%d', $redisHost, $redisPort),
        $client
    );

});

afterEach(function () {
    $this->cache->clear();
});

/* ── 1. convenience set()/get() ───────────────────────────────────── */
test('Redis set()/get()', function () {
    expect($this->cache->get('none'))->toBeNull()
        ->and($this->cache->set('foo', 'bar'))->toBeTrue()
        ->and($this->cache->get('foo'))->toBe('bar');
});

/* ─── PSR-16 get($key, $default) ───────────────────────────────── */
test('get returns default when key missing (redis)', function () {
    expect($this->cache->get('nobody', 'dflt'))->toBe('dflt');

    $default = static fn(): string => 'xyz';
    expect($this->cache->get('dynamic', $default))->toBe($default)
        ->and($this->cache->has('dynamic'))->toBeFalse();
});

test('get throws for invalid key (redis)', function () {
    expect(fn () => $this->cache->get('bad key', 'v'))
        ->toThrow(CacheInvalidArgumentException::class);
});

/* ── 2. PSR-6 behaviour ─────────────────────────────────────────── */
test('getItem()/save() (redis)', function () {
    $it = $this->cache->getItem('psr');
    expect($it)->toBeInstanceOf(CacheItem::class)
        ->and($it->isHit())->toBeFalse();

    $it->set(777)->save();
    expect($this->cache->getItem('psr')->get())->toBe(777);
});

/* ── 3. deferred queue ──────────────────────────────────────────── */
test('saveDeferred() & commit() (redis)', function () {
    $this->cache->getItem('a')->set('A')->saveDeferred();
    expect($this->cache->get('a'))->toBeNull();

    $this->cache->commit();
    expect($this->cache->get('a'))->toBe('A');
});

/* ── 4. ArrayAccess ─────────────────────────────────────────────── */
test('ArrayAccess (redis)', function () {
    $this->cache['k'] = 12;
    expect($this->cache['k'])->toBe(12)
        ->and(method_exists($this->cache, '__get'))->toBeFalse();
});

/* ── 6. TTL expiration ─────────────────────────────────────────── */
test('expiration honours TTL (redis)', function () {
    $this->cache->getItem('ttl')->set('x')->expiresAfter(1)->save();
    usleep(2_000_000);
    expect($this->cache->hasItem('ttl'))->toBeFalse();
});

/* ── 7. closure round-trip ──────────────────────────────────────── */
test('closure persists in redis', function () {
    $double = fn ($n) => $n * 2;
    $this->cache->getItem('cb')->set($double)->save();
    $fn = $this->cache->getItem('cb')->get();
    expect($fn(5))->toBe(10);
});

/* ── 9. invalid key guard ───────────────────────────────────────── */
test('invalid key throws (redis)', function () {
    expect(fn () => $this->cache->set('bad key', 'v'))
        ->toThrow(InvalidArgumentException::class);
});

/* ── 10. clear wipes namespace ----------------------------------- */
test('clear() wipes entries (redis)', function () {
    $this->cache->set('z', 9);
    $this->cache->clear();
    expect($this->cache->hasItem('z'))->toBeFalse();
});

test('Redis adapter multiFetch()', function () {
    $this->cache->set('r1', 10);
    $this->cache->set('r2', 20);

    $items = $this->cache->getItems(['r1', 'r2', 'none']);

    expect($items['r1']->get())->toBe(10)
        ->and($items['r2']->get())->toBe(20)
        ->and($items['none']->isHit())->toBeFalse();
});
