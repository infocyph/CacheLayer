<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cache\Adapter\ArrayCacheAdapter;
use Infocyph\CacheLayer\Cache\Adapter\PdoCacheAdapter;
use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\CacheLayer\Cache\CacheOptions;
use Infocyph\CacheLayer\Counter\AtomicCounters;
use Infocyph\CacheLayer\Node\Adapter\NodeSqliteCacheAdapter;
use Infocyph\CacheLayer\Node\Exception\NodeCacheStorageException;
use Infocyph\CacheLayer\Serializer\SignedClosureSerializer;

test('adapter policy is immutable from the first facade binding', function () {
    $adapter = new ArrayCacheAdapter('shared-policy');
    new Cache($adapter, options: new CacheOptions(allowObjects: false, integrityKey: 'first-secret'));

    expect(fn() => new Cache(
        $adapter,
        options: new CacheOptions(allowObjects: true, integrityKey: 'second-secret'),
    ))->toThrow(LogicException::class);

    expect(fn() => new Cache(
        $adapter,
        options: new CacheOptions(allowObjects: false, integrityKey: 'first-secret'),
    ))->not->toThrow(LogicException::class);
});

test('node SQLite cache preserves caller-owned transactions', function () {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE business_rows (id INTEGER PRIMARY KEY, value TEXT NOT NULL)');

    $adapter = new NodeSqliteCacheAdapter($pdo, 'node-transaction');
    $item = $adapter->createItem('cache-key')->set('cache-value');

    $pdo->beginTransaction();
    $pdo->exec("INSERT INTO business_rows (value) VALUES ('business-value')");

    expect(fn() => $adapter->saveMany([$item]))
        ->toThrow(NodeCacheStorageException::class)
        ->and($pdo->inTransaction())->toBeTrue()
        ->and((int) $pdo->query('SELECT COUNT(*) FROM business_rows')->fetchColumn())->toBe(1);

    $pdo->rollBack();
});

test('filesystem adapters reject a symlinked namespace root', function () {
    foreach ([
        'file' => static fn(string $namespace, string $base): Cache => Cache::file($namespace, $base),
        'php-files' => static fn(string $namespace, string $base): Cache => Cache::phpFiles($namespace, $base),
    ] as $label => $factory) {
        $base = sys_get_temp_dir() . '/cachelayer-symlink-' . $label . '-' . bin2hex(random_bytes(4));
        $target = sys_get_temp_dir() . '/cachelayer-symlink-target-' . $label . '-' . bin2hex(random_bytes(4));
        mkdir($base, 0700, true);
        mkdir($target, 0700, true);
        $link = $base . DIRECTORY_SEPARATOR . 'cache_attacker';
        expect(symlink($target, $link))->toBeTrue();

        try {
            expect(fn() => $factory('attacker', $base))->toThrow(RuntimeException::class);
        } finally {
            if (is_link($link)) {
                unlink($link);
            }
            if (is_dir($target)) {
                rmdir($target);
            }
            if (is_dir($base)) {
                rmdir($base);
            }
        }
    }
});

test('Redis DSN errors never echo credentials', function () {
    $secret = 'audit-password';
    $dsn = 'redis://user:' . $secret . '@localhost/not-a-database';

    try {
        AtomicCounters::redis('secret-test', $dsn);
        test()->fail('Expected invalid Redis DSN.');
    } catch (Throwable $failure) {
        expect($failure->getMessage())->not->toContain($secret)
            ->and($failure->getMessage())->not->toContain($dsn);
    }
});

test('secret-bearing public parameters are marked sensitive', function () {
    $parameters = [
        [CacheOptions::class, '__construct', 'integrityKey'],
        [Cache::class, 'redis', 'dsn'],
        [Cache::class, 'valkey', 'dsn'],
        [Cache::class, 'mongodb', 'uri'],
        [Cache::class, 'pdo', 'dsn'],
        [Cache::class, 'pdo', 'password'],
        [PdoCacheAdapter::class, '__construct', 'dsn'],
        [PdoCacheAdapter::class, '__construct', 'password'],
        [AtomicCounters::class, 'redis', 'dsn'],
        [AtomicCounters::class, 'valkey', 'dsn'],
        [SignedClosureSerializer::class, '__construct', 'key'],
    ];

    foreach ($parameters as [$class, $method, $parameter]) {
        $reflection = new ReflectionParameter([$class, $method], $parameter);
        expect($reflection->getAttributes(SensitiveParameter::class))->not->toBeEmpty();
    }
});
