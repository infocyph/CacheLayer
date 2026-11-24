<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Tests\Cache;

/**
 * tests/ApcuCachePoolTest.php
 *
 * Run these only when the APCu extension is loaded **and**
 * enabled at CLI startup (apc.enable_cli=1).
 * Discovery fails when this required prerequisite is missing.
 */

use Infocyph\CacheLayer\Cache\Cache;
use Closure;
use InvalidArgumentException;
use RuntimeException;
use Infocyph\CacheLayer\Cache\CacheOptions;
use Infocyph\CacheLayer\Exceptions\CacheInvalidArgumentException;

/* ── require CLI APCu ─────────────────────────────────────────────── */
if (! extension_loaded('apcu')) {
    throw new RuntimeException('APCu is required for the configured cache test matrix.');
}
if (! apcu_enabled()) {
    throw new RuntimeException('APCu must be enabled for CLI tests.');
}

/* ── boilerplate ──────────────────────────────────────────────────── */
$apcuFixtureCache = Cache::nullStore();

beforeEach(function () use (&$apcuFixtureCache): void {
    apcu_clear_cache();                           // fresh memory
    $apcuFixtureCache = Cache::apcu('tests', new CacheOptions(allowClosures: true));          // APCu-backed pool
});

afterEach(function (): void {
    apcu_clear_cache();
});

/* ─── convenience get()/set() ─────────────────────────────────────── */
test('convenience set() and get() (apcu)', function () use (&$apcuFixtureCache): void {
    expect($apcuFixtureCache->get('nope'))->toBeNull()
        ->and($apcuFixtureCache->set('foo', 'bar', 60))->toBeTrue()
        ->and($apcuFixtureCache->get('foo'))->toBe('bar');
});

/* ─── PSR-16 get($key, $default) ─────────────────────────────────── */
test('get returns default when key missing (apcu)', function () use (&$apcuFixtureCache): void {
    // Scalar default
    expect($apcuFixtureCache->get('missing', 'default'))->toBe('default');

    $default = static fn(): string => 'computed';
    expect($apcuFixtureCache->get('dyn', $default))->toBe($default)
        ->and($apcuFixtureCache->has('dyn'))->toBeFalse();
});

test('get throws for invalid key (apcu)', function () use (&$apcuFixtureCache): void {
    expect(fn (): mixed => $apcuFixtureCache->get('bad key', 'x'))
        ->toThrow(CacheInvalidArgumentException::class);
});

/* ─── PSR-6 behaviour ─────────────────────────────────────────────── */
test('PSR-6 getItem()/save() (apcu)', function () use (&$apcuFixtureCache): void {
    $item = \Infocyph\CacheLayer\Tests\Support\CacheFixture::item($apcuFixtureCache, 'psr');
    expect($item->isHit())->toBeFalse();

    $item->set(99)->expiresAfter(null)->save();
    expect($apcuFixtureCache->getItem('psr')->get())->toBe(99);
});

/* ─── deferred queue ──────────────────────────────────────────────── */
test('saveDeferred() and commit() (apcu)', function () use (&$apcuFixtureCache): void {
    \Infocyph\CacheLayer\Tests\Support\CacheFixture::item($apcuFixtureCache, 'x')->set('X')->saveDeferred();
    expect($apcuFixtureCache->get('x'))->toBe('X');

    $apcuFixtureCache->commit();
    expect($apcuFixtureCache->get('x'))->toBe('X');
});

/* ─── ArrayAccess ─────────────────────────────────────────────────── */
test('ArrayAccess (apcu)', function () use (&$apcuFixtureCache): void {
    $apcuFixtureCache['k'] = 11;
    expect($apcuFixtureCache['k'])->toBe(11)
        ->and((new \ReflectionClass($apcuFixtureCache))->hasMethod('__get'))->toBeFalse();
});

/* ─── TTL / expiration ───────────────────────────────────────────── */
test('expiration honours TTL (apcu)', function () use (&$apcuFixtureCache): void {
    \Infocyph\CacheLayer\Tests\Support\CacheFixture::item($apcuFixtureCache, 'ttl')->set('live')->expiresAfter(1)->save();
    usleep(2_000_000);
    expect($apcuFixtureCache->hasItem('ttl'))->toBeFalse();
});

/* ─── closure round-trip ──────────────────────────────────────────── */
test('closure value survives APCu', function () use (&$apcuFixtureCache): void {
    $fn = fn (int $n): int => $n + 5;
    \Infocyph\CacheLayer\Tests\Support\CacheFixture::item($apcuFixtureCache, 'cb')->set($fn)->save();
    $g = $apcuFixtureCache->getItem('cb')->get();
    assert($g instanceof Closure);
    expect($g(10))->toBe(15);
});

/* ─── invalid key triggers exception ─────────────────────────────── */
test('invalid key throws (apcu)', function () use (&$apcuFixtureCache): void {
    expect(fn (): bool => $apcuFixtureCache->set('bad key', 'v'))
        ->toThrow(InvalidArgumentException::class);
});

/* ─── clear() empties cache ───────────────────────────────────────── */
test('clear() wipes all entries (apcu)', function () use (&$apcuFixtureCache): void {
    $apcuFixtureCache->set('q', '1');
    $apcuFixtureCache->clear();
    expect($apcuFixtureCache->hasItem('q'))->toBeFalse();
});

test('APCu adapter multiFetch()', function () use (&$apcuFixtureCache): void {
    $apcuFixtureCache->set('x', 'X');
    $apcuFixtureCache->set('y', 'Y');

    $items = iterator_to_array($apcuFixtureCache->getItems(['x', 'y', 'z']));

    expect($items['x']->isHit())->toBeTrue()
        ->and($items['x']->get())->toBe('X')
        ->and($items['y']->get())->toBe('Y')
        ->and($items['z']->isHit())->toBeFalse();
});
