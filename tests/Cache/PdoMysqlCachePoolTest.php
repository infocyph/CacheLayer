<?php

use Infocyph\CacheLayer\Cache\Cache;

if (! in_array('mysql', PDO::getAvailableDrivers(), true)) {
    test('MySQL PDO driver not present')->skip();

    return;
}

$dsn = getenv('CACHELAYER_MYSQL_DSN') ?: 'mysql:host=127.0.0.1;port=3306;dbname=cachelayer';
$user = getenv('CACHELAYER_MYSQL_USER') ?: 'root';
$pass = getenv('CACHELAYER_MYSQL_PASS');
if ($pass === false) {
    $pass = '';
}

try {
    $probe = new PDO($dsn, $user, $pass);
    $probe->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $probe->query('SELECT 1');
} catch (Throwable) {
    test('MySQL server unreachable')->skip();

    return;
}

beforeEach(function () use ($dsn, $user, $pass) {
    $this->cache = Cache::pdo('mysql-tests', $dsn, $user, $pass, null, 'cachelayer_entries');
    $this->cache->clear();
});

afterEach(function () {
    $this->cache->clear();
});

test('pdo adapter set and get on mysql', function () {
    expect($this->cache->set('alpha', 11))->toBeTrue()
        ->and($this->cache->get('alpha'))->toBe(11);
});

test('pdo adapter ttl expiry on mysql', function () {
    $this->cache->set('ttl', 'v', 1);
    usleep(2_000_000);

    expect($this->cache->get('ttl'))->toBeNull();
});

test('pdo adapter delete and count on mysql', function () {
    $this->cache->set('a', 'A');
    $this->cache->set('b', 'B');

    expect($this->cache->count())->toBe(2);

    $this->cache->delete('a');
    expect($this->cache->count())->toBe(1)
        ->and($this->cache->get('a'))->toBeNull()
        ->and($this->cache->get('b'))->toBe('B');
});
