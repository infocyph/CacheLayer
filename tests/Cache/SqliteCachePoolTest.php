<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Tests\Cache;

/**
 * tests/SqliteCachePoolTest.php
 *
 * Runs only when the PDO SQLite driver is available.
 */

use Infocyph\CacheLayer\Cache\Cache;
use Closure;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Infocyph\CacheLayer\Cache\CacheOptions;
use Infocyph\CacheLayer\Exceptions\CacheInvalidArgumentException;

/* ── Skip entire suite if SQLite missing ─────────────────────────── */
if (! in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    throw new RuntimeException('PDO SQLite is required for the configured cache test matrix.');
}

/* ── bootstrap / teardown ────────────────────────────────────────── */
$sqliteFixtureDbFile = '';
$sqliteFixtureCache = Cache::nullStore();

beforeEach(function () use (&$sqliteFixtureDbFile, &$sqliteFixtureCache): void {
    $sqliteFixtureDbFile = sys_get_temp_dir().'/pest_sqlite_'.uniqid().'.sqlite';
    $sqliteFixtureCache = Cache::sqlite('tests', $sqliteFixtureDbFile, new CacheOptions(allowClosures: true));
});

afterEach(function () use (&$sqliteFixtureDbFile, &$sqliteFixtureCache): void {
    // Release SQLite handles before unlink on Windows.
    $sqliteFixtureCache = Cache::nullStore();
    gc_collect_cycles();
    if (is_file($sqliteFixtureDbFile)) {
        unlink($sqliteFixtureDbFile);
    }
});

/* ── 1. convenience set / get ───────────────────────────────────── */
test('sqlite set()/get()', function () use (&$sqliteFixtureCache): void {
    expect($sqliteFixtureCache->get('none'))->toBeNull()
        ->and($sqliteFixtureCache->set('foo', 'bar'))->toBeTrue()
        ->and($sqliteFixtureCache->get('foo'))->toBe('bar');
});

/* ─── PSR-16 get($key, $default) ───────────────────────────────── */
test('get returns default when key missing (sqlite)', function () use (&$sqliteFixtureCache): void {
    expect($sqliteFixtureCache->get('none', 'dflt'))->toBe('dflt');

    $default = static fn(): string => 'val';
    expect($sqliteFixtureCache->get('compute', $default))->toBe($default)
        ->and($sqliteFixtureCache->has('compute'))->toBeFalse();
});

test('get throws for invalid key (sqlite)', function () use (&$sqliteFixtureCache): void {
    expect(fn (): mixed => $sqliteFixtureCache->get('bad key', 'v'))
        ->toThrow(CacheInvalidArgumentException::class);
});

/* ── 2. PSR-6 behaviour ─────────────────────────────────────────── */
test('getItem()/save() (sqlite)', function () use (&$sqliteFixtureCache): void {
    $item = \Infocyph\CacheLayer\Tests\Support\CacheFixture::item($sqliteFixtureCache, 'psr');
    expect($item->isHit())->toBeFalse();

    $item->set(42)->save();
    expect($sqliteFixtureCache->getItem('psr')->get())->toBe(42);
});

/* ── 3. deferred queue ──────────────────────────────────────────── */
test('saveDeferred() & commit() (sqlite)', function () use (&$sqliteFixtureCache): void {
    \Infocyph\CacheLayer\Tests\Support\CacheFixture::item($sqliteFixtureCache, 'a')->set('A')->saveDeferred();
    expect($sqliteFixtureCache->get('a'))->toBe('A');

    $sqliteFixtureCache->commit();
    expect($sqliteFixtureCache->get('a'))->toBe('A');
});

/* ── 4. ArrayAccess ─────────────────────────────────────────────── */
test('ArrayAccess (sqlite)', function () use (&$sqliteFixtureCache): void {
    $sqliteFixtureCache['x'] = 5;
    expect($sqliteFixtureCache['x'])->toBe(5)
        ->and((new \ReflectionClass($sqliteFixtureCache))->hasMethod('__get'))->toBeFalse();
});

/* ── 6. TTL expiration ─────────────────────────────────────────── */
test('expiration honours TTL (sqlite)', function () use (&$sqliteFixtureCache): void {
    \Infocyph\CacheLayer\Tests\Support\CacheFixture::item($sqliteFixtureCache, 'ttl')->set('x')->expiresAfter(1)->save();
    usleep(2_000_000);
    expect($sqliteFixtureCache->hasItem('ttl'))->toBeFalse();
});

/* ── 7. closure round-trip ──────────────────────────────────────── */
test('closure survives sqlite', function () use (&$sqliteFixtureCache): void {
    $fn = fn (int $n): int => $n + 3;
    \Infocyph\CacheLayer\Tests\Support\CacheFixture::item($sqliteFixtureCache, 'cb')->set($fn)->save();
    $restored = $sqliteFixtureCache->getItem('cb')->get();
    assert($restored instanceof Closure);
    expect($restored(4))->toBe(7);
});

/* ── 9. invalid key guard ───────────────────────────────────────── */
test('invalid key throws (sqlite)', function () use (&$sqliteFixtureCache): void {
    expect(fn (): bool => $sqliteFixtureCache->set('bad key', 'v'))
        ->toThrow(InvalidArgumentException::class);
});

/* ── 10. clear wipes all entries ───────────────────────────────── */
test('clear() flushes table (sqlite)', function () use (&$sqliteFixtureCache): void {
    $sqliteFixtureCache->set('z', 9);
    $sqliteFixtureCache->clear();
    expect($sqliteFixtureCache->hasItem('z'))->toBeFalse();
});

test('SQLite adapter multiFetch()', function () use (&$sqliteFixtureCache): void {
    $sqliteFixtureCache->set('s1', 'A');
    $sqliteFixtureCache->set('s2', 'B');

    $items = iterator_to_array($sqliteFixtureCache->getItems(['s1', 's2', 'void']));

    expect($items['s1']->get())->toBe('A')
        ->and($items['s2']->get())->toBe('B')
        ->and($items['void']->isHit())->toBeFalse();
});
