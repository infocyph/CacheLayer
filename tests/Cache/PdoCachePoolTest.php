<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Tests\Cache;

use Infocyph\CacheLayer\Cache\Adapter\PdoCacheAdapter;
use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\CacheLayer\Cache\Lock\PdoLockProvider;
use PDO;
use ReflectionObject;
use RuntimeException;

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
    expect($provider)->toBeInstanceOf(PdoLockProvider::class);
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


test('SQLite bulk preserves binary, null, false, zero and misses', function () use (&$pdoFixtureCache): void {
    $values = ['nil' => null, 'zero' => 0, 'false' => false, 'binary' => "a\0\xffb"];
    expect($pdoFixtureCache->setMultiple($values, 600))->toBeTrue()
        ->and(iterator_to_array($pdoFixtureCache->getMultiple([...array_keys($values), 'missing'], 'absent')))
        ->toBe([...$values, 'missing' => 'absent']);
    foreach ($values as $key => $value) {
        expect($pdoFixtureCache->has($key))->toBeTrue()
            ->and($pdoFixtureCache->get($key, 'absent'))->toBe($value);
    }
    expect($pdoFixtureCache->has('missing'))->toBeFalse();
});


test('SQLite independent workers initialize a fresh database and share one cold computation', function (): void {
    $file = sys_get_temp_dir() . '/cachelayer-race-' . bin2hex(random_bytes(8)) . '.sqlite';
    $dsn = 'sqlite:' . $file;
    try {
        $results = \Infocyph\CacheLayer\Tests\Support\CacheFixture::cacheRace([
            'backend' => 'pdo', 'operation' => 'remember', 'namespace' => 'sqlite-race',
            'dsn' => $dsn, 'user' => '', 'password' => '',
        ]);
        $peer = Cache::pdo('sqlite-race', $dsn, table: 'cachelayer_schema_race');
        expect($results)->toHaveCount(12)
            ->and(array_unique($results))->toHaveCount(1)
            ->and($peer->get('race'))->toBe($results[0] ?? null);
        unset($peer);
    } finally {
        foreach ([$file, $file . '-wal', $file . '-shm'] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
});


test('SQLite atomic busy exhaustion leaves the connection usable', function (): void {
    $file = sys_get_temp_dir() . '/cachelayer-busy-' . bin2hex(random_bytes(8)) . '.sqlite';
    $dsn = 'sqlite:' . $file;
    $adapter = $peer = $holder = null;
    try {
        $holder = new PDO($dsn, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $peer = new PDO($dsn, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $adapter = new PdoCacheAdapter('busy', $dsn, pdo: $peer);
        $peer->exec('PRAGMA busy_timeout = 1');
        $holder->exec('BEGIN IMMEDIATE');
        try {
            $item = $adapter->createItem('claim')->set(1);
            expect(fn (): bool => $adapter->atomicSetIfAbsent($item))->toThrow(\PDOException::class);
        } finally {
            $holder->exec('ROLLBACK');
        }
        expect($adapter->atomicSetIfAbsent($item))->toBeTrue()
            ->and($adapter->getItem('claim')->get())->toBe(1);
    } finally {
        unset($adapter, $peer, $holder);
        foreach ([$file, $file . '-wal', $file . '-shm'] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
});
