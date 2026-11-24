<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cache\Cache;

if (! in_array('mysql', PDO::getAvailableDrivers(), true)) {
    throw new RuntimeException('PDO MySQL is required for the configured cache test matrix.');
}

$dsn = getenv('IC_MYSQL_DSN') ?: getenv('CACHELAYER_MYSQL_DSN') ?: 'mysql:host=127.0.0.1;port=3306;dbname=cachelayer';
$user = getenv('IC_SERVICE_USERNAME') ?: getenv('IC_MYSQL_USER') ?: getenv('CACHELAYER_MYSQL_USER') ?: 'root';
$pass = getenv('IC_SERVICE_PASSWORD') ?: getenv('IC_MYSQL_PASSWORD') ?: getenv('CACHELAYER_MYSQL_PASS');
if ($pass === false) {
    $pass = '';
}

try {
    $probe = new PDO($dsn, $user, $pass);
    $probe->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $probe->query('SELECT 1');
} catch (Throwable $failure) {
    throw new RuntimeException('MySQL service is required for the configured cache test matrix.', 0, $failure);
}

$pdomysqlFixtureCache = Cache::nullStore();

beforeEach(function () use ($dsn, $user, $pass, &$pdomysqlFixtureCache): void {
    $pdomysqlFixtureCache = Cache::pdo('mysql-tests', $dsn, $user, $pass, null, 'cachelayer_entries');
    $pdomysqlFixtureCache->clear();
});

afterEach(function () use (&$pdomysqlFixtureCache): void {
    $pdomysqlFixtureCache->clear();
});

test('pdo adapter set and get on mysql', function () use (&$pdomysqlFixtureCache): void {
    expect($pdomysqlFixtureCache->set('alpha', 11))->toBeTrue()
        ->and($pdomysqlFixtureCache->get('alpha'))->toBe(11);
});

test('pdo adapter ttl expiry on mysql', function () use (&$pdomysqlFixtureCache): void {
    $pdomysqlFixtureCache->set('ttl', 'v', 1);
    usleep(2_000_000);

    expect($pdomysqlFixtureCache->get('ttl'))->toBeNull();
});

test('pdo adapter bulk delete on mysql', function () use (&$pdomysqlFixtureCache): void {
    $pdomysqlFixtureCache->set('a', 'A');
    $pdomysqlFixtureCache->set('b', 'B');

    $pdomysqlFixtureCache->deleteMultiple(['a']);
    expect($pdomysqlFixtureCache->get('a'))->toBeNull()
        ->and($pdomysqlFixtureCache->get('b'))->toBe('B');
});
