<?php

use Infocyph\CacheLayer\Cache\Cache;

beforeEach(function () {
    $this->cacheDir = sys_get_temp_dir().'/pest_phpfiles_cache_'.uniqid();
    $this->cache = Cache::phpFiles('php-files-tests', $this->cacheDir);
});

afterEach(function () {
    if (! is_dir($this->cacheDir)) {
        return;
    }

    $it = new RecursiveDirectoryIterator($this->cacheDir, FilesystemIterator::SKIP_DOTS);
    $rim = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($rim as $file) {
        $path = $file->getRealPath();
        if ($path === false || ! file_exists($path)) {
            continue;
        }
        $file->isDir() ? rmdir($path) : unlink($path);
    }
    if (is_dir($this->cacheDir)) {
        rmdir($this->cacheDir);
    }
});

test('php files adapter persists values', function () {
    $this->cache->set('x', 'X');

    $again = Cache::phpFiles('php-files-tests', $this->cacheDir);

    expect($again->get('x'))->toBe('X');
});

test('php files adapter honors ttl', function () {
    $this->cache->set('ttl', 'v', 1);
    usleep(2_000_000);

    expect($this->cache->get('ttl'))->toBeNull();
});
