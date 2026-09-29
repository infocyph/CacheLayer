<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Tests\Cache\Support;

use Infocyph\CacheLayer\Cache\Adapter\FileCacheAdapter;
use Psr\Cache\CacheItemInterface;
use RuntimeException;

final class FaultingFileCacheAdapter extends FileCacheAdapter
{
    public ?string $failure = null;

    public bool $throws = true;

    public function clear(): bool
    {
        return !$this->fails('clear') && parent::clear();
    }

    public function deleteItem(string $key): bool
    {
        return !$this->fails('deleteItem') && parent::deleteItem($key);
    }

    public function deleteItems(array $keys): bool
    {
        return !$this->fails('deleteItems') && parent::deleteItems($keys);
    }

    public function save(CacheItemInterface $item): bool
    {
        return !$this->fails('save') && parent::save($item);
    }

    public function saveItems(array $items): bool
    {
        return !$this->fails('saveItems') && parent::saveItems($items);
    }

    private function fails(string $operation): bool
    {
        if ($this->failure !== $operation) {
            return false;
        }
        if ($this->throws) {
            throw new RuntimeException('Injected tier mutation failure.');
        }

        return true;
    }
}
