<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Support;

use InvalidArgumentException;

final class RedisConnection
{
    public static function connect(string $dsn): \Redis
    {
        $parts = parse_url($dsn);
        $scheme = is_array($parts) ? $parts['scheme'] ?? null : null;
        $host = is_array($parts) ? $parts['host'] ?? null : null;
        if (
            !is_string($scheme)
            || !in_array($scheme, ['redis', 'valkey'], true)
            || !is_string($host)
            || $host === ''
        ) {
            throw new InvalidArgumentException('Invalid Redis-compatible DSN.');
        }

        $path = $parts['path'] ?? '';
        $database = $path === '' || $path === '/'
            ? null
            : ltrim($path, '/');
        if ($database !== null && !ctype_digit($database)) {
            throw new InvalidArgumentException('Invalid Redis-compatible DSN.');
        }

        $port = $parts['port'] ?? 6379;
        if ($port === 0) {
            throw new InvalidArgumentException('Invalid Redis-compatible DSN.');
        }

        $connection = new \Redis();
        $connection->connect($host, $port);
        if (is_string($parts['pass'] ?? null) && $parts['pass'] !== '') {
            $connection->auth($parts['pass']);
        }
        if ($database !== null) {
            $connection->select((int) $database);
        }

        return $connection;
    }
}
