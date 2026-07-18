<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cache\Adapter\PdoCacheAdapter;
use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\CacheLayer\Cache\Lock\FileLockProvider;

if (! in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    test('PDO SQLite driver not present')->skip();

    return;
}

beforeEach(function () {
    $this->cache = Cache::pdo('pdo-tests', 'sqlite::memory:');
});

test('pdo adapter set and get with sqlite', function () {
    expect($this->cache->set('alpha', 11))->toBeTrue()
        ->and($this->cache->get('alpha'))->toBe(11);
});

test('pdo adapter ttl expiry with sqlite', function () {
    $this->cache->set('ttl', 'v', 1);
    usleep(2_000_000);

    expect($this->cache->get('ttl'))->toBeNull();
});

test('pdo adapter delete and count with sqlite', function () {
    $this->cache->set('a', 'A');
    $this->cache->set('b', 'B');

    expect($this->cache->count())->toBe(2);

    $this->cache->delete('a');
    expect($this->cache->count())->toBe(1)
        ->and($this->cache->get('a'))->toBeNull()
        ->and($this->cache->get('b'))->toBe('B');
});

test('pdo defaults to sqlite driver when no dsn is provided', function () {
    $namespace = 'pdo-default-'.uniqid();
    $cache = Cache::pdo($namespace);
    $cache->set('x', 'X');

    $again = Cache::pdo($namespace);
    expect($again->get('x'))->toBe('X');

    $dbFile = PdoCacheAdapter::defaultSqliteFileForNamespace($namespace);
    $cache->clear();
    if (is_file($dbFile)) {
        unlink($dbFile);
    }
});

test('pdo factory configures pdo lock provider', function () {
    $cache = Cache::pdo('pdo-lock-tests', 'sqlite::memory:');
    $prop = (new ReflectionObject($cache))->getProperty('lockProvider');
    $provider = $prop->getValue($cache);
    $pdoLockProviderClass = 'Infocyph\\CacheLayer\\Cache\\Lock\\PdoLockProvider';

    if (class_exists($pdoLockProviderClass)) {
        expect($provider)->toBeInstanceOf($pdoLockProviderClass);

        return;
    }

    expect($provider)->toBeInstanceOf(FileLockProvider::class);
});
