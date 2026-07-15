<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Counter;

use Infocyph\CacheLayer\Counter\Exception\AtomicCounterException;

final class AtomicCounters
{
    public static function redis(
        string $namespace = 'default',
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
        string $dsn = 'valkey://127.0.0.1:6379',
        ?\Redis $client = null,
    ): AtomicCounterStoreInterface {
        return self::redis($namespace, $dsn, $client);
    }

    private static function connect(string $dsn): \Redis
    {
        $parts = parse_url($dsn);
        if (!is_array($parts)) {
            throw new AtomicCounterException("Invalid Redis-compatible DSN: {$dsn}");
        }

        $client = new \Redis();
        $host = is_string($parts['host'] ?? null) ? $parts['host'] : '127.0.0.1';
        $port = is_int($parts['port'] ?? null) ? $parts['port'] : 6379;
        $client->connect($host, $port);
        if (is_string($parts['pass'] ?? null) && $parts['pass'] !== '') {
            $client->auth($parts['pass']);
        }
        if (is_string($parts['path'] ?? null) && $parts['path'] !== '/') {
            $client->select((int) ltrim($parts['path'], '/'));
        }

        return $client;
    }
}
