<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cache;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use Infocyph\CacheLayer\Exceptions\CacheInvalidArgumentException;

/** @internal */
final class CacheInput
{
    /** @throws CacheInvalidArgumentException */
    public static function expiryTimestamp(int $ttl, int $now): int
    {
        if (($now > 0 && $ttl > PHP_INT_MAX - $now)
            || ($now < 0 && $ttl < PHP_INT_MIN - $now)) {
            throw new CacheInvalidArgumentException('TTL is outside the supported timestamp range.');
        }

        return $now + $ttl;
    }

    /** @throws CacheInvalidArgumentException */
    public static function key(string $key): void
    {
        if (strlen($key) < 1 || strlen($key) > 64 || preg_match('/^[A-Za-z0-9_.-]+$/D', $key) !== 1) {
            throw new CacheInvalidArgumentException(
                'Cache keys must contain 1-64 characters from A-Z, a-z, 0-9, _, ., and -.',
            );
        }
    }

    /**
     * @param array<array-key, mixed> $keys
     * @return list<string>
     * @throws CacheInvalidArgumentException
     */
    public static function keys(array $keys): array
    {
        $validated = [];
        foreach ($keys as $key) {
            if (!is_string($key)) {
                throw new CacheInvalidArgumentException('Cache keys must be strings.');
            }
            self::key($key);
            $validated[] = $key;
        }

        return $validated;
    }

    /**
     * @param iterable<mixed> $keys
     * @return list<string>
     * @throws CacheInvalidArgumentException
     */
    public static function materializeKeys(iterable $keys): array
    {
        $materialized = [];
        foreach ($keys as $key) {
            if (!is_string($key)) {
                throw new CacheInvalidArgumentException('Cache keys must be strings.');
            }
            $materialized[] = $key;
        }

        return self::keys($materialized);
    }

    /** @throws CacheInvalidArgumentException */
    public static function namespace(string $namespace): string
    {
        if (strlen($namespace) < 1
            || strlen($namespace) > 64
            || preg_match('/^[A-Za-z0-9_.-]+$/D', $namespace) !== 1) {
            throw new CacheInvalidArgumentException(
                'Cache namespaces must contain 1-64 characters from A-Z, a-z, 0-9, _, ., and -.',
            );
        }

        return $namespace;
    }

    /** @throws CacheInvalidArgumentException */
    public static function relativeTtl(int $expiresAt, int $now): int
    {
        if ($now > 0 && $expiresAt < PHP_INT_MIN + $now) {
            // The exact negative duration is unrepresentable, but the item is already expired.
            return PHP_INT_MIN;
        }
        if ($now < 0 && $expiresAt > PHP_INT_MAX + $now) {
            throw new CacheInvalidArgumentException('TTL is outside the supported timestamp range.');
        }

        return $expiresAt - $now;
    }

    /**
     * @param array<array-key, mixed> $tags
     * @return list<string>
     * @throws CacheInvalidArgumentException
     */
    public static function tags(array $tags): array
    {
        $validated = [];
        $seen = [];
        foreach ($tags as $tag) {
            if (!is_string($tag)
                || strlen($tag) < 1
                || strlen($tag) > 64
                || preg_match('/^[A-Za-z0-9_.-]+$/D', $tag) !== 1) {
                throw new CacheInvalidArgumentException(
                    'Cache tags must contain 1-64 characters from A-Z, a-z, 0-9, _, ., and -.',
                );
            }
            if (!isset($seen[$tag])) {
                $seen[$tag] = true;
                $validated[] = $tag;
            }
        }

        return $validated;
    }

    /** @throws CacheInvalidArgumentException */
    public static function ttl(mixed $ttl): ?int
    {
        if ($ttl === null) {
            return null;
        }
        if (is_int($ttl)) {
            if ($ttl > 0 && $ttl > PHP_INT_MAX - time()) {
                throw new CacheInvalidArgumentException('TTL is outside the supported timestamp range.');
            }

            return $ttl;
        }
        if ($ttl instanceof DateInterval) {
            $now = new DateTimeImmutable();

            return self::relativeTtl($now->add($ttl)->getTimestamp(), $now->getTimestamp());
        }
        if ($ttl instanceof DateTimeInterface) {
            return self::relativeTtl($ttl->getTimestamp(), time());
        }

        throw new CacheInvalidArgumentException(
            'TTL must be null, an integer, DateInterval, or DateTimeInterface.',
        );
    }
}
