<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Support;

use InvalidArgumentException;

final class RedisConnection
{
    public static function connect(string $dsn): \Redis
    {
        $parts = parse_url($dsn);
        if (!is_array($parts)) {
            throw new InvalidArgumentException('Invalid Redis-compatible DSN.');
        }

        $connection = new \Redis();
        $host = is_string($parts['host'] ?? null) ? $parts['host'] : '127.0.0.1';
        $port = is_int($parts['port'] ?? null) ? $parts['port'] : 6379;
        $connection->connect($host, $port);
        if (is_string($parts['pass'] ?? null) && $parts['pass'] !== '') {
            $connection->auth($parts['pass']);
        }
        if (is_string($parts['path'] ?? null) && $parts['path'] !== '/') {
            $connection->select((int) ltrim($parts['path'], '/'));
        }

        return $connection;
    }
}
