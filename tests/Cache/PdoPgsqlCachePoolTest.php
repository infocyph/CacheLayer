<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\CacheLayer\Cache\Lock\PdoLockProvider;

if (! in_array('pgsql', PDO::getAvailableDrivers(), true)) {
    throw new RuntimeException('PDO PostgreSQL is required for the configured cache test matrix.');
}

$dsn = getenv('IC_POSTGRES_DSN') ?: getenv('CACHELAYER_PG_DSN') ?: 'pgsql:host=127.0.0.1;port=5432;dbname=cachelayer';
$user = getenv('IC_SERVICE_USERNAME') ?: getenv('IC_POSTGRES_USER') ?: getenv('CACHELAYER_PG_USER') ?: 'postgres';
$pass = getenv('IC_SERVICE_PASSWORD') ?: getenv('IC_POSTGRES_PASSWORD') ?: getenv('CACHELAYER_PG_PASS') ?: 'postgres';

try {
    $probe = new PDO($dsn, $user, $pass);
    $probe->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $probe->query('SELECT 1');
} catch (Throwable $failure) {
    throw new RuntimeException('PostgreSQL service is required for the configured cache test matrix.', 0, $failure);
}

$pdopgsqlFixtureCache = Cache::nullStore();

beforeEach(function () use ($dsn, $user, $pass, &$pdopgsqlFixtureCache): void {
    $pdopgsqlFixtureCache = Cache::pdo('pg-tests', $dsn, $user, $pass, null, 'cachelayer_entries');
    $pdopgsqlFixtureCache->clear();
});

afterEach(function () use (&$pdopgsqlFixtureCache): void {
    $pdopgsqlFixtureCache->clear();
});

test('pdo adapter set and get on pgsql', function () use (&$pdopgsqlFixtureCache): void {
    expect($pdopgsqlFixtureCache->set('alpha', 11))->toBeTrue()
        ->and($pdopgsqlFixtureCache->get('alpha'))->toBe(11);
});

test('pdo adapter ttl expiry on pgsql', function () use (&$pdopgsqlFixtureCache): void {
    $pdopgsqlFixtureCache->set('ttl', 'v', 1);
    usleep(2_000_000);

    expect($pdopgsqlFixtureCache->get('ttl'))->toBeNull();
});

test('pdo adapter bulk delete on pgsql', function () use (&$pdopgsqlFixtureCache): void {
    $pdopgsqlFixtureCache->set('a', 'A');
    $pdopgsqlFixtureCache->set('b', 'B');

    $pdopgsqlFixtureCache->deleteMultiple(['a']);
    expect($pdopgsqlFixtureCache->get('a'))->toBeNull()
        ->and($pdopgsqlFixtureCache->get('b'))->toBe('B');
});

test('PostgreSQL advisory locks accept native PDO booleans and release ownership', function () use ($dsn, $user, $pass) {
    $connect = static function () use ($dsn, $user, $pass): PDO {
        return new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    };
    $holder = new PdoLockProvider($connect(), 'cachelayer:test:');
    $contender = new PdoLockProvider($connect(), 'cachelayer:test:');
    $key = 'postgres-lock-' . bin2hex(random_bytes(8));
    $handle = $holder->acquire($key, 0.0, 5.0);

    expect($handle)->not->toBeNull()
        ->and($holder->refresh($handle, 5.0))->toBeTrue()
        ->and($contender->acquire($key, 0.0, 5.0))->toBeNull();

    $holder->release($handle);

    $replacement = $contender->acquire($key, 0.0, 5.0);
    expect($holder->refresh($handle, 5.0))->toBeFalse()
        ->and($replacement)->not->toBeNull();
    $contender->release($replacement);
});
