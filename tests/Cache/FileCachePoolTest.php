<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Tests\Cache;

/**  tests/FileCachePoolTest.php  */

use Infocyph\CacheLayer\Cache\Cache;
use Closure;
use FilesystemIterator;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Infocyph\CacheLayer\Cache\CacheOptions;
use Infocyph\CacheLayer\Cache\Item\CacheItem;
use Infocyph\CacheLayer\Exceptions\CacheInvalidArgumentException;

$fileFixtureCacheDir = '';
$fileFixtureCache = Cache::nullStore();

beforeEach(function () use (&$fileFixtureCacheDir, &$fileFixtureCache): void {
    /* fresh temp directory for each run */
    $fileFixtureCacheDir = sys_get_temp_dir().'/pest_cache_'.uniqid();

    /* build a file-backed cachepool via static factory */
    $fileFixtureCache = Cache::file('tests', $fileFixtureCacheDir, new CacheOptions(allowClosures: true));
});

afterEach(function () use (&$fileFixtureCacheDir): void {
    /* recursive dir cleanup */
    if (! is_dir($fileFixtureCacheDir)) {
        return;
    }

    $it = new RecursiveDirectoryIterator($fileFixtureCacheDir, FilesystemIterator::SKIP_DOTS);
    $rim = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);

    foreach ($rim as $file) {
        if (!$file instanceof \SplFileInfo) {
            throw new \UnexpectedValueException('Unexpected filesystem iterator entry.');
        }
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($fileFixtureCacheDir);
});

/* ───────────────────────────────────────────────────────────── */

/* ─── convenience set()/get() ─────────────────────────────────── */
test('convenience set() and get()', function () use (&$fileFixtureCache): void {
    expect($fileFixtureCache->get('nope'))->toBeNull()
        ->and($fileFixtureCache->set('foo', 'bar', 60))->toBeTrue()
        ->and($fileFixtureCache->get('foo'))->toBe('bar');
});

/* ─── PSR-16 get($key, $default) ───────────────────────────────── */
test('get returns default when key missing (file)', function () use (&$fileFixtureCache): void {
    // Scalar default
    expect($fileFixtureCache->get('missing', 'def'))->toBe('def');

    $default = static fn(): string => 'xyz';
    expect($fileFixtureCache->get('x', $default))->toBe($default)
        ->and($fileFixtureCache->has('x'))->toBeFalse();
});

test('get throws for invalid key (file)', function () use (&$fileFixtureCache): void {
    expect(fn (): mixed => $fileFixtureCache->get('bad key', 'v'))
        ->toThrow(CacheInvalidArgumentException::class);
});

/* ─── PSR-6 getItem()/save() ───────────────────────────────────── */
test('PSR-6 getItem()/save()', function () use (&$fileFixtureCache): void {
    $item = $fileFixtureCache->getItem('psr');
    assert($item instanceof CacheItem);

    expect($item)->toBeInstanceOf(CacheItem::class)
        ->and($item->isHit())->toBeFalse();

    $item->set(123)->expiresAfter(null)->save();

    $fetched = $fileFixtureCache->getItem('psr');
    expect($fetched->isHit())->toBeTrue()
        ->and($fetched->get())->toBe(123);
});

test('saveDeferred() and commit()', function () use (&$fileFixtureCache): void {
    expect($fileFixtureCache->get('a'))->toBeNull();

    \Infocyph\CacheLayer\Tests\Support\CacheFixture::item($fileFixtureCache, 'a')->set('A')->saveDeferred();
    \Infocyph\CacheLayer\Tests\Support\CacheFixture::item($fileFixtureCache, 'b')->set('B')->saveDeferred();

    expect($fileFixtureCache->get('a'))->toBe('A');

    $fileFixtureCache->commit();

    expect($fileFixtureCache->get('a'))->toBe('A')
        ->and($fileFixtureCache->get('b'))->toBe('B');
});

test('ArrayAccess support', function () use (&$fileFixtureCache): void {
    $fileFixtureCache['x'] = 10;

    expect(isset($fileFixtureCache['x']))->toBeTrue()
        ->and($fileFixtureCache['x'])->toBe(10);

    unset($fileFixtureCache['x']);
    expect(isset($fileFixtureCache['x']))->toBeFalse();
});

test('runtime storage configuration is immutable', function () use (&$fileFixtureCache): void {
    expect(method_exists($fileFixtureCache, '__get'))->toBeFalse()
        ->and(method_exists($fileFixtureCache, 'setNamespaceAndDirectory'))->toBeFalse();
});

test('expiration honours TTL', function () use (&$fileFixtureCache): void {
    \Infocyph\CacheLayer\Tests\Support\CacheFixture::item($fileFixtureCache, 'ttl')->set('x')->expiresAfter(1)->save();
    usleep(2_000_000);
    expect($fileFixtureCache->getItem('ttl')->isHit())->toBeFalse();
});

test('closure round-trips via ClosureSerializer', function () use (&$fileFixtureCache): void {
    $double = fn (int $n): int => $n * 2;
    \Infocyph\CacheLayer\Tests\Support\CacheFixture::item($fileFixtureCache, 'cb')->set($double)->save();
    $restored = $fileFixtureCache->getItem('cb')->get();
    assert($restored instanceof Closure);
    expect($restored(7))->toBe(14);
});

test('invalid cache key throws', function () use (&$fileFixtureCache): void {
    expect(fn (): bool => $fileFixtureCache->set('space key', 'v'))
        ->toThrow(InvalidArgumentException::class);
});

test('persistence across pool instances', function () use (&$fileFixtureCacheDir, &$fileFixtureCache): void {
    $fileFixtureCache->set('persist', 'yes');

    $again = Cache::file('tests', $fileFixtureCacheDir); // new instance
    expect($again->get('persist'))->toBe('yes');
});

test('clear() removes everything', function () use (&$fileFixtureCache): void {
    $fileFixtureCache->set('a', 1);
    $fileFixtureCache->set('b', 2);

    $fileFixtureCache->clear();

    expect($fileFixtureCache->hasItem('a'))->toBeFalse()
        ->and($fileFixtureCache->hasItem('b'))->toBeFalse();
});

test('file adapter bulk getItems()', function () use (&$fileFixtureCache): void {
    $fileFixtureCache->set('a', 1);
    $fileFixtureCache->set('b', 2);

    $items = iterator_to_array($fileFixtureCache->getItems(['a', 'b', 'c']));

    expect($items['a']->isHit())->toBeTrue()
        ->and($items['a']->get())->toBe(1)
        ->and($items['b']->get())->toBe(2)
        ->and($items['c']->isHit())->toBeFalse();
});

test('clear uses the literal cache directory and preserves neighboring stores', function () use (&$fileFixtureCacheDir): void {
    $literal = Cache::file('clear', $fileFixtureCacheDir . '/[store]');
    $neighbor = Cache::file('clear', $fileFixtureCacheDir . '/s');
    expect($literal->set('key', 'literal'))->toBeTrue()
        ->and($literal->setTagged('tagged', 'literal', ['tag']))->toBeTrue()
        ->and($neighbor->set('key', 'neighbor'))->toBeTrue()
        ->and($literal->clear())->toBeTrue()
        ->and($literal->has('key'))->toBeFalse()
        ->and($literal->has('tagged'))->toBeFalse()
        ->and($neighbor->get('key'))->toBe('neighbor');
});
