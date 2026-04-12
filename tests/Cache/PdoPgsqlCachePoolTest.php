<?php

use Infocyph\CacheLayer\Cache\Cache;

if (!in_array('pgsql', PDO::getAvailableDrivers(), true)) {
    test('PostgreSQL PDO driver not present')->skip();
    return;
}

$dsn = getenv('CACHELAYER_PG_DSN') ?: 'pgsql:host=127.0.0.1;port=5432;dbname=cachelayer';
$user = getenv('CACHELAYER_PG_USER') ?: 'postgres';
$pass = getenv('CACHELAYER_PG_PASS') ?: 'postgres';

try {
    $probe = new PDO($dsn, $user, $pass);
    $probe->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $probe->query('SELECT 1');
} catch (Throwable) {
    test('PostgreSQL server unreachable')->skip();
    return;
}

beforeEach(function () use ($dsn, $user, $pass) {
    $this->cache = Cache::pdo('pg-tests', $dsn, $user, $pass, null, 'cachelayer_entries');
    $this->cache->clear();
});

afterEach(function () {
    $this->cache->clear();
});

test('pdo adapter set and get on pgsql', function () {
    expect($this->cache->set('alpha', 11))->toBeTrue()
        ->and($this->cache->get('alpha'))->toBe(11);
});

test('pdo adapter ttl expiry on pgsql', function () {
    $this->cache->set('ttl', 'v', 1);
    usleep(2_000_000);

    expect($this->cache->get('ttl'))->toBeNull();
});

test('pdo adapter delete and count on pgsql', function () {
    $this->cache->set('a', 'A');
    $this->cache->set('b', 'B');

    expect($this->cache->count())->toBe(2);

    $this->cache->delete('a');
    expect($this->cache->count())->toBe(1)
        ->and($this->cache->get('a'))->toBeNull()
        ->and($this->cache->get('b'))->toBe('B');
});
