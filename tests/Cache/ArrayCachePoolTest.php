<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\CacheLayer\Cache\Adapter\AbstractCacheAdapter;
use Infocyph\CacheLayer\Cache\Item\GenericCacheItem;
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

test('array adapter getItem returns GenericCacheItem', function () {
    $item = $this->cache->getItem('x');

    expect($item)->toBeInstanceOf(GenericCacheItem::class)
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

test('deferred commit attempts every queued item after a save failure', function () {
    $adapter = new class extends AbstractCacheAdapter
    {
        /** @var list<string> */
        public array $attempted = [];

        public function clear(): bool
        {
            return true;
        }

        public function count(): int
        {
            return 0;
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

        public function getItem(string $key): GenericCacheItem
        {
            return new GenericCacheItem($this, $key);
        }

        public function hasItem(string $key): bool
        {
            return $key === "\0";
        }

        public function save(CacheItemInterface $item): bool
        {
            $this->attempted[] = $item->getKey();

            return $item->getKey() !== 'first';
        }

        protected function supportsItem(CacheItemInterface $item): bool
        {
            return $item instanceof GenericCacheItem;
        }
    };

    $adapter->saveDeferred($adapter->getItem('first')->set(1));
    $adapter->saveDeferred($adapter->getItem('second')->set(2));

    expect($adapter->commit())->toBeFalse()
        ->and($adapter->attempted)->toBe(['first', 'second'])
        ->and($adapter->commit())->toBeTrue();
});
