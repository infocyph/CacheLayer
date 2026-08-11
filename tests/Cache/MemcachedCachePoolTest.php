<?php

declare(strict_types=1);

/**
 * tests/MemcachedCachePoolTest.php
 *
 * Runs only when the Memcached extension is loaded *and*
 * a Memcached daemon is reachable at 127.0.0.1:11211.
 */

use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\CacheLayer\Cache\Item\CacheItem;
use Infocyph\CacheLayer\Cache\Lock\MemcachedLockProvider;
use Infocyph\CacheLayer\Exceptions\CacheInvalidArgumentException;

/* ── Skip suite if Memcached unavailable ─────────────────────────── */

if (! class_exists(Memcached::class)) {
    test('Memcached ext not loaded – skipping')->skip();

    return;
}

$memcachedHost = getenv('IC_MEMCACHED_HOST') ?: getenv('CACHELAYER_MEMCACHED_HOST') ?: '127.0.0.1';
$memcachedPort = (int) (getenv('IC_MEMCACHED_PORT') ?: getenv('CACHELAYER_MEMCACHED_PORT') ?: '11211');

$probe = new Memcached;
$probe->addServer($memcachedHost, $memcachedPort);
$probe->set('ping', 'pong');
if ($probe->getResultCode() !== Memcached::RES_SUCCESS) {
    test('No Memcached server available – skipping')->skip();

    return;
}

/* ── Test bootstrap / teardown ───────────────────────────────────── */

beforeEach(function () use ($memcachedHost, $memcachedPort) {
    $client = new Memcached;
    $client->addServer($memcachedHost, $memcachedPort);
    $client->flush();                          // fresh slate

    $this->client = $client;
    $this->cache = Cache::memcached(
        'tests',
        [[$memcachedHost, $memcachedPort, 0]],
        $client
    );

});

afterEach(function () {
    $this->client->flush();
});

/* ── Convenience helpers ────────────────────────────────────────── */

/* ─── convenience set()/get() ─────────────────────────────────── */
test('memcache set()/get()', function () {
    expect($this->cache->get('miss'))->toBeNull()
        ->and($this->cache->set('foo', 'bar'))->toBeTrue()
        ->and($this->cache->get('foo'))->toBe('bar');
});

/* ─── PSR-16 get($key, $default) ───────────────────────────────── */
test('get returns default when key missing (memcached)', function () {
    // Scalar
    expect($this->cache->get('nobody', 'dflt'))->toBe('dflt');

    $default = static fn(): string => 'hello';
    expect($this->cache->get('call', $default))->toBe($default)
        ->and($this->cache->has('call'))->toBeFalse();
});

test('get throws for invalid key (memcached)', function () {
    expect(fn () => $this->cache->get('bad key', 'x'))
        ->toThrow(CacheInvalidArgumentException::class);
});

/* ─── PSR-6 getItem()/save() ───────────────────────────────────── */
test('PSR-6 getItem()/save()', function () {
    $it = $this->cache->getItem('psr');
    expect($it)->toBeInstanceOf(CacheItem::class)
        ->and($it->isHit())->toBeFalse();

    $it->set(321)->save();
    expect($this->cache->getItem('psr')->get())->toBe(321);
});

test('saveDeferred() + commit()', function () {
    $this->cache->getItem('a')->set('A')->saveDeferred();
    expect($this->cache->get('a'))->toBeNull();

    $this->cache->commit();
    expect($this->cache->get('a'))->toBe('A');
});

test('ArrayAccess is the only property-like access', function () {
    $this->cache['x'] = 7;
    expect($this->cache['x'])->toBe(7)
        ->and(method_exists($this->cache, '__get'))->toBeFalse();
});

test('TTL expiration', function () {
    $this->cache->getItem('ttl')->set('x')->expiresAfter(1)->save();
    usleep(2_000_000);
    expect($this->cache->hasItem('ttl'))->toBeFalse();
});

test('closure round-trip', function () {
    $fn = fn ($n) => $n * 3;
    $this->cache->getItem('cb')->set($fn)->save();
    $g = $this->cache->getItem('cb')->get();
    expect($g(3))->toBe(9);
});

test('invalid key throws', function () {
    expect(fn () => $this->cache->set('bad key', 'v'))
        ->toThrow(InvalidArgumentException::class);
});

test('clear only rotates this namespace generation', function () use ($memcachedHost, $memcachedPort) {
    $other = Cache::memcached('other', [[$memcachedHost, $memcachedPort, 0]], $this->client);
    $this->cache->set('z', 9);
    $other->set('z', 10);
    $this->cache->clear();
    expect($this->cache->hasItem('z'))->toBeFalse()
        ->and($other->get('z'))->toBe(10);
});

test('an expired Memcached lock owner cannot delete its replacement', function () {
    $provider = new MemcachedLockProvider($this->client);
    $oldOwner = $provider->acquire('reports', 0.0, 30.0);
    expect($oldOwner)->not->toBeNull();
    if ($oldOwner === null) {
        return;
    }

    $this->client->set($oldOwner->key, 'replacement-token', 30);
    $provider->release($oldOwner);

    expect($this->client->get($oldOwner->key))->toBe('replacement-token');
});

test('Memcached adapter multiFetch()', function () {
    $this->cache->set('m1', 'foo');
    $this->cache->set('m2', 'bar');

    $items = $this->cache->getItems(['m1', 'm2', 'missing']);

    expect($items['m1']->get())->toBe('foo')
        ->and($items['m2']->get())->toBe('bar')
        ->and($items['missing']->isHit())->toBeFalse();
});
