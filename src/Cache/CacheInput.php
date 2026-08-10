<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cache;

use DateInterval;
use DateTimeImmutable;
use Infocyph\CacheLayer\Exceptions\CacheInvalidArgumentException;

/** @internal */
final class CacheInput
{
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

    /**
     * @param array<array-key, mixed> $tags
     * @return list<string>
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

    public static function ttl(mixed $ttl): ?int
    {
        if ($ttl === null || is_int($ttl)) {
            return $ttl;
        }
        if ($ttl instanceof DateInterval) {
            $now = new DateTimeImmutable();

            return $now->add($ttl)->getTimestamp() - $now->getTimestamp();
        }

        throw new CacheInvalidArgumentException('TTL must be null, an integer, or DateInterval.');
    }
}
