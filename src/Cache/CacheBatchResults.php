<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cache;

use Psr\Cache\CacheItemInterface;

/** @internal */
final class CacheBatchResults
{
    /**
     * @param list<string> $keys
     * @param array<int|string, CacheItemInterface> $items
     * @return iterable<string, CacheItemInterface>
     */
    public static function items(array $keys, array $items): iterable
    {
        if (!self::requiresKeyPreservation($keys)) {
            return $items;
        }

        return (static function () use ($keys, $items): \Generator {
            foreach ($keys as $key) {
                yield $key => $items[$key];
            }
        })();
    }

    /**
     * @param list<string> $keys
     * @param iterable<string, CacheItemInterface> $items
     * @return iterable<string, mixed>
     */
    public static function values(array $keys, iterable $items, mixed $default): iterable
    {
        $byIdentity = [];
        foreach ($items as $item) {
            $byIdentity["key:\0" . $item->getKey()] = $item;
        }

        if (!self::requiresKeyPreservation($keys)) {
            $values = [];
            foreach ($keys as $key) {
                $item = $byIdentity["key:\0" . $key];
                $values[$key] = $item->isHit() ? $item->get() : $default;
            }

            return $values;
        }

        return (static function () use ($keys, $byIdentity, $default): \Generator {
            foreach ($keys as $key) {
                $item = $byIdentity["key:\0" . $key];

                yield $key => $item->isHit() ? $item->get() : $default;
            }
        })();
    }

    /** @param list<string> $keys */
    private static function requiresKeyPreservation(array $keys): bool
    {
        return array_any(
            $keys,
            static function (string $key): bool {
                $probe = [$key => true];

                return array_key_first($probe) !== $key;
            },
        );
    }
}
