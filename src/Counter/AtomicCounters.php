<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Counter;

use Infocyph\CacheLayer\Counter\Exception\AtomicCounterException;
use Infocyph\CacheLayer\Support\RedisConnection;
use InvalidArgumentException;

final class AtomicCounters
{
    public static function redis(
        string $namespace = 'default',
        #[\SensitiveParameter]
        string $dsn = 'redis://127.0.0.1:6379',
        ?\Redis $client = null,
    ): AtomicCounterStoreInterface {
        if (!class_exists(\Redis::class)) {
            throw new AtomicCounterException('phpredis extension not loaded');
        }

        return new RedisAtomicCounterStore($client ?? self::connect($dsn), $namespace);
    }

    public static function valkey(
        string $namespace = 'default',
        #[\SensitiveParameter]
        string $dsn = 'valkey://127.0.0.1:6379',
        ?\Redis $client = null,
    ): AtomicCounterStoreInterface {
        return self::redis($namespace, $dsn, $client);
    }

    private static function connect(#[\SensitiveParameter] string $dsn): \Redis
    {
        try {
            return RedisConnection::connect($dsn);
        } catch (InvalidArgumentException $exception) {
            throw new AtomicCounterException('Invalid Redis-compatible DSN.', 0, $exception);
        }
    }
}
