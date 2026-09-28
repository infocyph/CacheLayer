<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cache\Adapter;

use Infocyph\CacheLayer\Cache\Item\CacheItem;
use Psr\Cache\CacheItemInterface;

final class NullCacheAdapter extends AbstractCacheAdapter
{
    public function clear(): bool
    {
        $this->deferred = [];

        return true;
    }

    public function deleteItem(string $key): bool
    {
        $this->discardDeferredKey($key);
        unset($key);

        return true;
    }

    /**
     * @param array $keys The keys argument.
     * @phpstan-param list<string> $keys
     */
    public function deleteItems(array $keys): bool
    {
        $this->discardDeferredKeys($keys);
        unset($keys);

        return true;
    }

    public function getItem(string $key): CacheItem
    {
        return $this->genericMiss($key);
    }

    /** @param list<string> $tags */
    #[\Override]
    public function getTagGenerations(array $tags): array
    {
        $generations = [];
        foreach ($tags as $tag) {
            $generations[$tag] = self::newGeneration();
        }

        return $generations;
    }

    public function hasItem(string $key): bool
    {
        return $this->getItem($key)->isHit();
    }

    /**
     * @param array $keys The keys argument.
     * @phpstan-param list<string> $keys
     * @phpstan-return array<string, CacheItem>
     */
    public function multiFetch(array $keys): array
    {
        $items = [];
        foreach ($keys as $key) {
            $items[$key] = $this->genericMiss($key);
        }

        return $items;
    }

    /** @param list<string> $tags */
    #[\Override]
    public function rotateTagGenerations(array $tags): bool
    {
        unset($tags);

        return true;
    }

    public function save(CacheItemInterface $item): bool
    {
        return $this->supportsItem($item);
    }

    /** @param array<string, CacheItemInterface> $items */
    public function saveItems(array $items): bool
    {
        foreach ($items as $item) {
            if (!$this->supportsItem($item)) {
                return false;
            }
        }

        return true;
    }
}
