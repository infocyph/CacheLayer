<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cache\Adapter;

use Infocyph\CacheLayer\Cache\Item\GenericCacheItem;
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
        return true;
    }

    public function deleteItems(array $keys): bool
    {
        return true;
    }

    public function getItem(string $key): GenericCacheItem
    {
        return new GenericCacheItem($this, $key);
    }

    public function hasItem(string $key): bool
    {
        return false;
    }

    public function multiFetch(array $keys): array
    {
        $items = [];
        foreach ($keys as $key) {
            $items[(string) $key] = new GenericCacheItem($this, (string) $key);
        }

        return $items;
    }

    public function save(CacheItemInterface $item): bool
    {
        return $this->supportsItem($item);
    }

    protected function supportsItem(CacheItemInterface $item): bool
    {
        return $item instanceof GenericCacheItem;
    }
}
