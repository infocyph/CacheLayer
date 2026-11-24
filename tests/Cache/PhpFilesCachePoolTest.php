<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cache\Cache;

$phpfilesFixtureCacheDir = '';
$phpfilesFixtureCache = Cache::nullStore();

beforeEach(function () use (&$phpfilesFixtureCacheDir, &$phpfilesFixtureCache): void {
    $phpfilesFixtureCacheDir = sys_get_temp_dir().'/pest_phpfiles_cache_'.uniqid();
    $phpfilesFixtureCache = Cache::phpFiles('php-files-tests', $phpfilesFixtureCacheDir);
});

afterEach(function () use (&$phpfilesFixtureCacheDir): void {
    if (! is_dir($phpfilesFixtureCacheDir)) {
        return;
    }

    $it = new RecursiveDirectoryIterator($phpfilesFixtureCacheDir, FilesystemIterator::SKIP_DOTS);
    $rim = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($rim as $file) {
        if (!$file instanceof \SplFileInfo) {
            throw new \UnexpectedValueException('Unexpected filesystem iterator entry.');
        }
        $path = $file->getRealPath();
        if ($path === false || ! file_exists($path)) {
            continue;
        }
        $file->isDir() ? rmdir($path) : unlink($path);
    }
    if (is_dir($phpfilesFixtureCacheDir)) {
        rmdir($phpfilesFixtureCacheDir);
    }
});

test('php files adapter persists values', function () use (&$phpfilesFixtureCacheDir, &$phpfilesFixtureCache): void {
    $phpfilesFixtureCache->set('x', 'X');

    $again = Cache::phpFiles('php-files-tests', $phpfilesFixtureCacheDir);

    expect($again->get('x'))->toBe('X');
});

test('php files adapter honors ttl', function () use (&$phpfilesFixtureCache): void {
    $phpfilesFixtureCache->set('ttl', 'v', 1);
    usleep(2_000_000);

    expect($phpfilesFixtureCache->get('ttl'))->toBeNull();
});

test('clear uses the literal cache directory and preserves neighboring stores', function () use (&$phpfilesFixtureCacheDir): void {
    $literal = Cache::phpFiles('clear', $phpfilesFixtureCacheDir . '/[store]');
    $neighbor = Cache::phpFiles('clear', $phpfilesFixtureCacheDir . '/s');
    expect($literal->set('key', 'literal'))->toBeTrue()
        ->and($literal->setTagged('tagged', 'literal', ['tag']))->toBeTrue()
        ->and($neighbor->set('key', 'neighbor'))->toBeTrue()
        ->and($literal->clear())->toBeTrue()
        ->and($literal->has('key'))->toBeFalse()
        ->and($literal->has('tagged'))->toBeFalse()
        ->and($neighbor->get('key'))->toBe('neighbor');
});
