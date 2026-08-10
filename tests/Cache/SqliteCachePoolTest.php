<?php

declare(strict_types=1);

/**
 * tests/SqliteCachePoolTest.php
 *
 * Runs only when the PDO SQLite driver is available.
 */

use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\CacheLayer\Cache\Item\CacheItem;
use Infocyph\CacheLayer\Exceptions\CacheInvalidArgumentException;

/* ── Skip entire suite if SQLite missing ─────────────────────────── */
if (! in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    test('SQLite PDO driver not present – skipping')->skip();

    return;
}

/* ── bootstrap / teardown ────────────────────────────────────────── */
beforeEach(function () {
    $this->dbFile = sys_get_temp_dir().'/pest_sqlite_'.uniqid().'.sqlite';
    $this->cache = Cache::sqlite('tests', $this->dbFile);
});

afterEach(function () {
    // Release SQLite handles before unlink on Windows.
    $this->cache = null;
    gc_collect_cycles();
    if (is_file($this->dbFile)) {
        unlink($this->dbFile);
    }
});

/* ── 1. convenience set / get ───────────────────────────────────── */
test('sqlite set()/get()', function () {
    expect($this->cache->get('none'))->toBeNull()
        ->and($this->cache->set('foo', 'bar'))->toBeTrue()
        ->and($this->cache->get('foo'))->toBe('bar');
});

/* ─── PSR-16 get($key, $default) ───────────────────────────────── */
test('get returns default when key missing (sqlite)', function () {
    expect($this->cache->get('none', 'dflt'))->toBe('dflt');

    $default = static fn(): string => 'val';
    expect($this->cache->get('compute', $default))->toBe($default)
        ->and($this->cache->has('compute'))->toBeFalse();
});

test('get throws for invalid key (sqlite)', function () {
    expect(fn () => $this->cache->get('bad key', 'v'))
        ->toThrow(CacheInvalidArgumentException::class);
});

/* ── 2. PSR-6 behaviour ─────────────────────────────────────────── */
test('getItem()/save() (sqlite)', function () {
    $item = $this->cache->getItem('psr');
    expect($item)->toBeInstanceOf(CacheItem::class)
        ->and($item->isHit())->toBeFalse();

    $item->set(42)->save();
    expect($this->cache->getItem('psr')->get())->toBe(42);
});

/* ── 3. deferred queue ──────────────────────────────────────────── */
test('saveDeferred() & commit() (sqlite)', function () {
    $this->cache->getItem('a')->set('A')->saveDeferred();
    expect($this->cache->get('a'))->toBeNull();

    $this->cache->commit();
    expect($this->cache->get('a'))->toBe('A');
});

/* ── 4. ArrayAccess ─────────────────────────────────────────────── */
test('ArrayAccess (sqlite)', function () {
    $this->cache['x'] = 5;
    expect($this->cache['x'])->toBe(5)
        ->and(method_exists($this->cache, '__get'))->toBeFalse();
});

/* ── 6. TTL expiration ─────────────────────────────────────────── */
test('expiration honours TTL (sqlite)', function () {
    $this->cache->getItem('ttl')->set('x')->expiresAfter(1)->save();
    usleep(2_000_000);
    expect($this->cache->hasItem('ttl'))->toBeFalse();
});

/* ── 7. closure round-trip ──────────────────────────────────────── */
test('closure survives sqlite', function () {
    $fn = fn ($n) => $n + 3;
    $this->cache->getItem('cb')->set($fn)->save();
    expect(($this->cache->getItem('cb')->get())(4))->toBe(7);
});

/* ── 9. invalid key guard ───────────────────────────────────────── */
test('invalid key throws (sqlite)', function () {
    expect(fn () => $this->cache->set('bad key', 'v'))
        ->toThrow(InvalidArgumentException::class);
});

/* ── 10. clear wipes all entries ───────────────────────────────── */
test('clear() flushes table (sqlite)', function () {
    $this->cache->set('z', 9);
    $this->cache->clear();
    expect($this->cache->hasItem('z'))->toBeFalse();
});

test('SQLite adapter multiFetch()', function () {
    $this->cache->set('s1', 'A');
    $this->cache->set('s2', 'B');

    $items = $this->cache->getItems(['s1', 's2', 'void']);

    expect($items['s1']->get())->toBe('A')
        ->and($items['s2']->get())->toBe('B')
        ->and($items['void']->isHit())->toBeFalse();
});
