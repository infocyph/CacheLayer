<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cache;

use Infocyph\CacheLayer\Cache\Item\CacheItem;
use Psr\Cache\CacheItemInterface;

/** @internal */
final class CacheTagSnapshots
{
    /**
     * @param array<string, CacheItemInterface> $items
     * @return list<string>
     */
    public static function collectTags(array $items): array
    {
        $tagSet = [];
        foreach ($items as $item) {
            if (!$item instanceof CacheItem || !$item->isHit()) {
                continue;
            }
            foreach ($item->getTagGenerations() as $tag => $_generation) {
                $tagSet[$tag] = true;
            }
        }

        return array_keys($tagSet);
    }

    /** @param array<string, string> $generations */
    public static function isCurrent(CacheItem $item, array $generations): bool
    {
        foreach ($item->getTagGenerations() as $tag => $expected) {
            $current = $generations[$tag] ?? null;
            if (!is_string($current) || !hash_equals($expected, $current)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, CacheItemInterface> $items
     * @param callable(string): CacheItemInterface $miss
     * @return array<string, CacheItemInterface>
     */
    public static function missTagged(array $items, callable $miss): array
    {
        foreach ($items as $key => $item) {
            if (self::isTaggedHit($item)) {
                $items[$key] = $miss($key);
            }
        }

        return $items;
    }

    /**
     * @param array<string, CacheItemInterface> $items
     * @param array<string, string> $generations
     * @param callable(string): CacheItemInterface $miss
     * @return array{items:array<string, CacheItemInterface>, stale:list<string>}
     */
    public static function rejectStale(array $items, array $generations, callable $miss): array
    {
        $stale = [];
        foreach ($items as $key => $item) {
            if (!$item instanceof CacheItem || !$item->isHit() || self::isCurrent($item, $generations)) {
                continue;
            }
            $stale[] = $key;
            $items[$key] = $miss($key);
        }

        return ['items' => $items, 'stale' => $stale];
    }

    private static function isTaggedHit(CacheItemInterface $item): bool
    {
        return $item instanceof CacheItem && $item->isHit() && $item->getTagGenerations() !== [];
    }
}
