<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Support;

use InvalidArgumentException;
use RuntimeException;
use ValueError;

final class RedisConnection
{
    private const float CONNECT_TIMEOUT_SECONDS = 1.0;

    private const float READ_TIMEOUT_SECONDS = 1.0;

    public static function connect(string $dsn): \Redis
    {
        [$host, $port, $database, $credentials] = self::parseDsn($dsn);
        $connection = new \Redis();
        if (!$connection->connect(
            $host,
            $port,
            self::CONNECT_TIMEOUT_SECONDS,
            null,
            0,
            self::READ_TIMEOUT_SECONDS,
        )) {
            throw new RuntimeException('Unable to connect to the Redis-compatible server.');
        }
        self::authenticate($connection, $credentials);
        if ($database !== null && !$connection->select($database)) {
            throw new RuntimeException('Unable to select the Redis-compatible database.');
        }

        return $connection;
    }

    /**
     * @param \Redis $connection The connected client.
     * @param string|array|null $credentials The optional Redis credentials.
     * @phpstan-param string|array{string, string}|null $credentials
     */
    private static function authenticate(\Redis $connection, string|array|null $credentials): void
    {
        if ($credentials !== null && !$connection->auth($credentials)) {
            throw new RuntimeException('Redis-compatible server authentication failed.');
        }
    }

    /**
     * @param array $parts The parsed DSN parts.
     * @phpstan-param array<string, int|string> $parts
     * @phpstan-return string|array{string, string}|null
     */
    private static function parseCredentials(array $parts): string|array|null
    {
        $pass = $parts['pass'] ?? null;
        if (!is_string($pass) || $pass === '') {
            return null;
        }

        $password = rawurldecode($pass);
        $user = $parts['user'] ?? null;
        if (!is_string($user) || $user === '') {
            return $password;
        }

        return [rawurldecode($user), $password];
    }

    /**
     * @param string $dsn The Redis-compatible DSN.
     * @phpstan-return array{string, int, int|null, string|array{string, string}|null}
     */
    private static function parseDsn(string $dsn): array
    {
        $parts = self::parseDsnParts($dsn);
        $scheme = $parts['scheme'] ?? null;
        $host = $parts['host'] ?? null;
        if (
            !is_string($scheme)
            || !in_array($scheme, ['redis', 'valkey'], true)
            || !is_string($host)
            || $host === ''
        ) {
            throw new InvalidArgumentException('Invalid Redis-compatible DSN.');
        }

        $path = $parts['path'] ?? '';
        if (!is_string($path)) {
            throw new InvalidArgumentException('Invalid Redis-compatible DSN.');
        }
        $database = $path === '' || $path === '/'
            ? null
            : ltrim($path, '/');
        $databaseId = $database === null
            ? null
            : filter_var($database, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($databaseId === false) {
            throw new InvalidArgumentException('Invalid Redis-compatible DSN.');
        }

        $port = $parts['port'] ?? 6379;
        if (!is_int($port) || $port < 1 || $port > 65_535) {
            throw new InvalidArgumentException('Invalid Redis-compatible DSN.');
        }

        return [$host, $port, $databaseId, self::parseCredentials($parts)];
    }

    /**
     * @param string $dsn The Redis-compatible DSN.
     * @phpstan-return array<string, int|string>
     */
    private static function parseDsnParts(string $dsn): array
    {
        try {
            $parts = parse_url($dsn);
        } catch (ValueError) {
            throw new InvalidArgumentException('Invalid Redis-compatible DSN.');
        }

        if (!is_array($parts)) {
            throw new InvalidArgumentException('Invalid Redis-compatible DSN.');
        }

        return $parts;
    }
}
