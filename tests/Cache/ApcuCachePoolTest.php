<?php

declare(strict_types=1);

/**
 * tests/ApcuCachePoolTest.php
 *
 * Run these only when the APCu extension is loaded **and**
 * enabled in CLI (apcu.enable_cli=1).  Otherwise the whole
 * suite is skipped.
 */

use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\CacheLayer\Cache\Item\CacheItem;
use Infocyph\CacheLayer\Exceptions\CacheInvalidArgumentException;

/* ── skip entirely if APCu unavailable ─────────────────────────────── */
if (! extension_loaded('apcu')) {
    test('APCu not loaded – skipping adapter tests')->skip();

    return;
}
ini_set('apcu.enable_cli', 1);
if (! apcu_enabled()) {
    test('APCu not enabled – skipping adapter tests')->skip();

    return;
}

/* ── boilerplate ──────────────────────────────────────────────────── */
beforeEach(function () {
    apcu_clear_cache();                           // fresh memory
    $this->cache = Cache::apcu('tests');          // APCu-backed pool
});

afterEach(function () {
    apcu_clear_cache();
});

/* ─── convenience get()/set() ─────────────────────────────────────── */
test('convenience set() and get() (apcu)', function () {
    expect($this->cache->get('nope'))->toBeNull()
        ->and($this->cache->set('foo', 'bar', 60))->toBeTrue()
        ->and($this->cache->get('foo'))->toBe('bar');
});

/* ─── PSR-16 get($key, $default) ─────────────────────────────────── */
test('get returns default when key missing (apcu)', function () {
    // Scalar default
    expect($this->cache->get('missing', 'default'))->toBe('default');

    $default = static fn(): string => 'computed';
    expect($this->cache->get('dyn', $default))->toBe($default)
        ->and($this->cache->has('dyn'))->toBeFalse();
});

test('get throws for invalid key (apcu)', function () {
    expect(fn () => $this->cache->get('bad key', 'x'))
        ->toThrow(CacheInvalidArgumentException::class);
});

/* ─── PSR-6 behaviour ─────────────────────────────────────────────── */
test('PSR-6 getItem()/save() (apcu)', function () {
    $item = $this->cache->getItem('psr');
    expect($item)->toBeInstanceOf(CacheItem::class)
        ->and($item->isHit())->toBeFalse();

    $item->set(99)->expiresAfter(null)->save();
    expect($this->cache->getItem('psr')->get())->toBe(99);
});

/* ─── deferred queue ──────────────────────────────────────────────── */
test('saveDeferred() and commit() (apcu)', function () {
    $this->cache->getItem('x')->set('X')->saveDeferred();
    expect($this->cache->get('x'))->toBeNull();

    $this->cache->commit();
    expect($this->cache->get('x'))->toBe('X');
});

/* ─── ArrayAccess ─────────────────────────────────────────────────── */
test('ArrayAccess (apcu)', function () {
    $this->cache['k'] = 11;
    expect($this->cache['k'])->toBe(11)
        ->and(method_exists($this->cache, '__get'))->toBeFalse();
});

/* ─── TTL / expiration ───────────────────────────────────────────── */
test('expiration honours TTL (apcu)', function () {
    $this->cache->getItem('ttl')->set('live')->expiresAfter(1)->save();
    usleep(2_000_000);
    expect($this->cache->hasItem('ttl'))->toBeFalse();
});

/* ─── closure round-trip ──────────────────────────────────────────── */
test('closure value survives APCu', function () {
    $fn = fn ($n) => $n + 5;
    $this->cache->getItem('cb')->set($fn)->save();
    $g = $this->cache->getItem('cb')->get();
    expect($g(10))->toBe(15);
});

/* ─── invalid key triggers exception ─────────────────────────────── */
test('invalid key throws (apcu)', function () {
    expect(fn () => $this->cache->set('bad key', 'v'))
        ->toThrow(InvalidArgumentException::class);
});

/* ─── clear() empties cache ───────────────────────────────────────── */
test('clear() wipes all entries (apcu)', function () {
    $this->cache->set('q', '1');
    $this->cache->clear();
    expect($this->cache->hasItem('q'))->toBeFalse();
});

test('APCu adapter multiFetch()', function () {
    $this->cache->set('x', 'X');
    $this->cache->set('y', 'Y');

    $items = $this->cache->getItems(['x', 'y', 'z']);

    expect($items['x']->isHit())->toBeTrue()
        ->and($items['x']->get())->toBe('X')
        ->and($items['y']->get())->toBe('Y')
        ->and($items['z']->isHit())->toBeFalse();
});
