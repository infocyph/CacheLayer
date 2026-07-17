<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Support\RedisConnection;

test('redis connection rejects malformed DSNs before creating a client', function () {
    expect(fn () => RedisConnection::connect('redis://'))
        ->toThrow(\InvalidArgumentException::class, 'Invalid Redis-compatible DSN.');
});
