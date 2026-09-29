<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Support\RedisConnection;

test('redis connection rejects malformed DSNs before creating a client', function (string $dsn) {
    expect(fn () => RedisConnection::connect($dsn))
        ->toThrow(\InvalidArgumentException::class, 'Invalid Redis-compatible DSN.');
})->with([
    'missing host' => 'redis://',
    'missing scheme' => '127.0.0.1:6379',
    'unsupported scheme' => 'http://127.0.0.1:6379',
    'invalid database' => 'redis://127.0.0.1/database',
    'invalid port' => 'redis://127.0.0.1:0',
]);


test('redis authentication helper marks every credential-bearing parameter sensitive', function () {
    $authenticate = new ReflectionMethod(RedisConnection::class, 'authenticate');
    $credentials = $authenticate->getParameters()[1];
    $parseCredentials = new ReflectionMethod(RedisConnection::class, 'parseCredentials');
    $parts = $parseCredentials->getParameters()[0];

    expect($credentials->getAttributes(SensitiveParameter::class))->toHaveCount(1)
        ->and($parts->getAttributes(SensitiveParameter::class))->toHaveCount(1);
});
