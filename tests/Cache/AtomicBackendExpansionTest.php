<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cache\AtomicCacheInterface;
use Infocyph\CacheLayer\Cache\Cache;

$cleanupTree = static function (string $directory): void {
    if (!is_dir($directory)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($directory);
};

test('WeakMap supports the full atomic cache contract', function () {
    $cache = Cache::weakMap('atomic-weak-map-contract');
    $atomic = $cache->atomic();

    expect($atomic)->toBeInstanceOf(AtomicCacheInterface::class)
        ->and($atomic->setIfAbsent('claim', 1, 30))->toBeTrue()
        ->and($atomic->setIfAbsent('claim', 2, 30))->toBeFalse()
        ->and($atomic->compareAndSet('claim', '1', 2, 30))->toBeFalse()
        ->and($atomic->compareAndSet('claim', 1, 2, 30))->toBeTrue()
        ->and($atomic->getAndDelete('claim', 'missing'))->toBe(2)
        ->and($atomic->getAndDelete('claim', 'missing'))->toBe('missing');
});

test('file and PHP-file stores support the full atomic cache contract', function () use ($cleanupTree) {
    foreach (['file', 'phpFiles'] as $factory) {
        $directory = sys_get_temp_dir() . '/cachelayer-atomic-' . strtolower($factory) . '-' . uniqid('', true);
        try {
            $cache = Cache::{$factory}('atomic-files', $directory);
            $atomic = $cache->atomic();

            expect($atomic)->toBeInstanceOf(AtomicCacheInterface::class)
                ->and($atomic->setIfAbsent('claim', 'first', 30))->toBeTrue()
                ->and($atomic->setIfAbsent('claim', 'second', 30))->toBeFalse()
                ->and($atomic->compareAndSet('claim', 'first', 'updated', 30))->toBeTrue()
                ->and($cache->get('claim'))->toBe('updated')
                ->and($atomic->getAndDelete('claim', 'missing'))->toBe('updated')
                ->and($cache->has('claim'))->toBeFalse();
        } finally {
            $cleanupTree($directory);
        }
    }
});

test('SQLite PDO supports the full atomic cache contract', function () {
    $file = sys_get_temp_dir() . '/cachelayer-atomic-pdo-' . uniqid('', true) . '.sqlite';
    try {
        $cache = Cache::sqlite('atomic-pdo', $file);
        $atomic = $cache->atomic();

        expect($atomic)->toBeInstanceOf(AtomicCacheInterface::class)
            ->and($atomic->setIfAbsent('claim', null, 30))->toBeTrue()
            ->and($atomic->compareAndSet('claim', null, 'updated', 30))->toBeTrue()
            ->and($atomic->getAndDelete('claim', 'missing'))->toBe('updated')
            ->and($atomic->getAndDelete('claim', 'missing'))->toBe('missing');
    } finally {
        if (is_file($file)) {
            unlink($file);
        }
    }
});

if (class_exists(Memcached::class)) {
    $host = getenv('IC_MEMCACHED_HOST') ?: getenv('CACHELAYER_MEMCACHED_HOST') ?: '127.0.0.1';
    $port = (int) (getenv('IC_MEMCACHED_PORT') ?: getenv('CACHELAYER_MEMCACHED_PORT') ?: '11211');
    $probe = new Memcached();
    $probe->addServer($host, $port);
    $available = $probe->set('cachelayer-atomic-probe', 'ok')
        && $probe->getResultCode() === Memcached::RES_SUCCESS;

    test('Memcached supports CAS-backed atomic cache operations', function () use ($host, $port) {
        $client = new Memcached();
        $client->addServer($host, $port);
        $client->flush();
        $cache = Cache::memcached('atomic-memcached', [[$host, $port, 0]], $client);
        $atomic = $cache->atomic();

        expect($atomic)->toBeInstanceOf(AtomicCacheInterface::class)
            ->and($atomic->setIfAbsent('claim', 'first', 30))->toBeTrue()
            ->and($atomic->setIfAbsent('claim', 'second', 30))->toBeFalse()
            ->and($atomic->compareAndSet('claim', 'first', 'updated', 30))->toBeTrue()
            ->and($atomic->getAndDelete('claim', 'missing'))->toBe('updated')
            ->and($cache->has('claim'))->toBeFalse()
            ->and($atomic->setIfAbsent('claim', 'reclaimed', 30))->toBeTrue()
            ->and($cache->get('claim'))->toBe('reclaimed');
    })->skip(!$available, 'No Memcached server available.');
}
