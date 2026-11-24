<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Tests\Cluster\Support;

use Infocyph\CacheLayer\Cache\Adapter\AbstractCacheAdapter;
use Infocyph\CacheLayer\Cache\Item\CacheItem;
use Psr\Cache\CacheItemInterface;

final class RejectingClusterCacheAdapter extends AbstractCacheAdapter
{
    /** @var array<string, mixed> */
    public array $rejectedOperations = [];

    #[\Override]
    public function clear(): bool
    {
        return $this->reject('clear');
    }

    #[\Override]
    public function deleteItem(string $key): bool
    {
        return $this->reject('deleteItem', $key);
    }

    #[\Override]
    public function deleteItems(array $keys): bool
    {
        return $this->reject('deleteItems', $keys);
    }

    #[\Override]
    public function getItem(string $key): CacheItem
    {
        return $this->genericMiss($key);
    }

    #[\Override]
    public function hasItem(string $key): bool
    {
        return $this->reject('hasItem', $key);
    }

    #[\Override]
    public function multiFetch(array $keys): array
    {
        $items = [];
        foreach ($keys as $key) {
            $items[$key] = $this->genericMiss($key);
        }

        return $items;
    }

    #[\Override]
    public function save(CacheItemInterface $item): bool
    {
        return $this->reject('save', $item);
    }

    #[\Override]
    public function saveItems(array $items): bool
    {
        return $this->reject('saveItems', $items);
    }

    private function reject(string $operation, mixed $argument = null): bool
    {
        $this->rejectedOperations[$operation] = $argument;

        return false;
    }
}
