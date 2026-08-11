<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\CacheLayer\Cache\Adapter\AbstractCacheAdapter;
use Infocyph\CacheLayer\Cache\Item\CacheItem;
use Psr\Cache\CacheItemInterface;

beforeEach(function () {
    $this->cache = Cache::memory('array-tests');
});

test('array adapter supports basic set/get/delete', function () {
    expect($this->cache->set('alpha', 1))->toBeTrue()
        ->and($this->cache->get('alpha'))->toBe(1)
        ->and($this->cache->delete('alpha'))->toBeTrue()
        ->and($this->cache->get('alpha'))->toBeNull();
});

test('array adapter getItem returns the shared CacheItem', function () {
    $item = $this->cache->getItem('x');

    expect($item)->toBeInstanceOf(CacheItem::class)
        ->and($item->isHit())->toBeFalse();
});

test('array adapter honors ttl', function () {
    $this->cache->set('ttl', 'v', 1);
    usleep(2_000_000);

    expect($this->cache->get('ttl'))->toBeNull();
});

test('array adapter supports getItems', function () {
    $this->cache->set('a', 'A');
    $this->cache->set('b', 'B');

    $items = $this->cache->getItems(['a', 'b', 'c']);

    expect($items['a']->isHit())->toBeTrue()
        ->and($items['a']->get())->toBe('A')
        ->and($items['b']->get())->toBe('B')
        ->and($items['c']->isHit())->toBeFalse();
});

test('deferred commit uses one bulk persistence call and retains failures', function () {
    $adapter = new class extends AbstractCacheAdapter
    {
        public int $bulkCalls = 0;

        public function clear(): bool
        {
            return true;
        }

        public function deleteItem(string $key): bool
        {
            return $key !== "\0";
        }

        public function deleteItems(array $keys): bool
        {
            foreach ($keys as $key) {
                if ($key === "\0") {
                    return false;
                }
            }

            return true;
        }

        public function getItem(string $key): CacheItem
        {
            return new CacheItem($this, $key);
        }

        /** @return array<string, CacheItem> */
        public function multiFetch(array $keys): array
        {
            return array_fill_keys($keys, new CacheItem($this, 'unused'));
        }

        public function hasItem(string $key): bool
        {
            return $key === "\0";
        }

        public function save(CacheItemInterface $item): bool
        {
            unset($item);

            return true;
        }

        public function saveItems(array $items): bool
        {
            unset($items);
            $this->bulkCalls++;

            return $this->bulkCalls > 1;
        }
    };

    $adapter->saveDeferred($adapter->getItem('first')->set(1));
    $adapter->saveDeferred($adapter->getItem('second')->set(2));

    expect($adapter->commit())->toBeFalse()
        ->and($adapter->bulkCalls)->toBe(1)
        ->and($adapter->commit())->toBeTrue()
        ->and($adapter->bulkCalls)->toBe(2)
        ->and($adapter->commit())->toBeTrue()
        ->and($adapter->bulkCalls)->toBe(2);
});
