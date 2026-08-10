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

    public function count(): int
    {
        return 0;
    }

    public function deleteItem(string $key): bool
    {
        unset($key);

        return true;
    }

    /**
     * @param array $keys The keys argument.
     * @phpstan-param list<string> $keys
     */
    public function deleteItems(array $keys): bool
    {
        unset($keys);

        return true;
    }

    public function getItem(string $key): CacheItem
    {
        return new CacheItem($this, $key);
    }

    /** @param list<string> $tags */
    #[\Override]
    public function getTagVersions(array $tags): array
    {
        return array_fill_keys($tags, 0);
    }

    public function hasItem(string $key): bool
    {
        unset($key);

        return false;
    }

    /** @param list<string> $tags */
    #[\Override]
    public function incrementTagVersions(array $tags): bool
    {
        unset($tags);

        return true;
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
            $items[$key] = new CacheItem($this, $key);
        }

        return $items;
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
