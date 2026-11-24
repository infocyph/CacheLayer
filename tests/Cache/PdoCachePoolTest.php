<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cache\Adapter\PdoCacheAdapter;
use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\CacheLayer\Cache\Lock\FileLockProvider;

if (! in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    throw new RuntimeException('PDO SQLite is required for the configured cache test matrix.');
}

$pdoFixtureCache = Cache::nullStore();

beforeEach(function () use (&$pdoFixtureCache): void {
    $pdoFixtureCache = Cache::pdo('pdo-tests', 'sqlite::memory:');
});

test('pdo adapter set and get with sqlite', function () use (&$pdoFixtureCache): void {
    expect($pdoFixtureCache->set('alpha', 11))->toBeTrue()
        ->and($pdoFixtureCache->get('alpha'))->toBe(11);
});

test('pdo adapter ttl expiry with sqlite', function () use (&$pdoFixtureCache): void {
    $pdoFixtureCache->set('ttl', 'v', 1);
    usleep(2_000_000);

    expect($pdoFixtureCache->get('ttl'))->toBeNull();
});

test('pdo adapter bulk delete with sqlite', function () use (&$pdoFixtureCache): void {
    $pdoFixtureCache->set('a', 'A');
    $pdoFixtureCache->set('b', 'B');
    $pdoFixtureCache->deleteMultiple(['a']);

    expect($pdoFixtureCache->get('a'))->toBeNull()
        ->and($pdoFixtureCache->get('b'))->toBe('B');
});

test('pdo defaults to sqlite driver when no dsn is provided', function (): void {
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

test('pdo factory configures pdo lock provider', function (): void {
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

test('PDO cache supports numeric-looking tag names without changing their identity', function (): void {
    $cache = Cache::pdo('pdo-numeric-tags', 'sqlite::memory:');
    expect($cache->setTagged('first', 'A', ['123']))->toBeTrue()
        ->and($cache->setTagged('second', 'B', ['0123']))->toBeTrue()
        ->and($cache->get('first'))->toBe('A')
        ->and($cache->get('second'))->toBe('B')
        ->and($cache->invalidateTag('123'))->toBeTrue()
        ->and($cache->has('first'))->toBeFalse()
        ->and($cache->get('second'))->toBe('B');
});

test('PDO bounded pruning removes only expired data for the selected namespace', function (): void {
    $pdo = new PDO('sqlite::memory:');
    $adapter = new PdoCacheAdapter('prune', pdo: $pdo);
    $insert = $pdo->prepare('INSERT INTO cachelayer_entries (namespace, kind, cache_key, payload, expires) VALUES (?, ?, ?, ?, ?)');
    foreach ([['prune', 'data', 'old1', time() - 10], ['prune', 'data', 'old2', time() - 10], ['prune', 'data', 'live', time() + 600], ['other', 'data', 'old3', time() - 10], ['prune', 'tag', 'tag', null]] as [$ns, $kind, $key, $expiry]) {
        $insert->execute([$ns, $kind, $key, 'payload', $expiry]);
    }
    expect($adapter->pruneExpired(1))->toBe(1)
        ->and($adapter->pruneExpired(1))->toBe(1)
        ->and($adapter->pruneExpired(1))->toBe(0)
        ->and(\Infocyph\CacheLayer\Tests\Support\CacheFixture::countRows($pdo, 'SELECT COUNT(*) FROM cachelayer_entries'))->toBe(3);
});
