<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\CacheLayer\Cache\Adapter\AbstractCacheAdapter;
use Infocyph\CacheLayer\Cache\Adapter\ArrayCacheAdapter;
use Infocyph\CacheLayer\Exceptions\CacheInvalidArgumentException;
use Infocyph\CacheLayer\Cache\Item\CacheItem;
use Psr\Cache\CacheItemInterface;

$arrayFixtureCache = Cache::nullStore();

beforeEach(function () use (&$arrayFixtureCache): void {
    $arrayFixtureCache = Cache::memory('array-tests');
});

test('array adapter supports basic set/get/delete', function () use (&$arrayFixtureCache): void {
    expect($arrayFixtureCache->set('alpha', 1))->toBeTrue()
        ->and($arrayFixtureCache->get('alpha'))->toBe(1)
        ->and($arrayFixtureCache->delete('alpha'))->toBeTrue()
        ->and($arrayFixtureCache->get('alpha'))->toBeNull();
});

test('array adapter getItem returns the shared CacheItem', function () use (&$arrayFixtureCache): void {
    $item = $arrayFixtureCache->getItem('x');

    expect($item)->toBeInstanceOf(CacheItem::class)
        ->and($item->isHit())->toBeFalse();
});

test('array adapter honors ttl', function () use (&$arrayFixtureCache): void {
    $arrayFixtureCache->set('ttl', 'v', 1);
    usleep(2_000_000);

    expect($arrayFixtureCache->get('ttl'))->toBeNull();
});

test('array adapter supports getItems', function () use (&$arrayFixtureCache): void {
    $arrayFixtureCache->set('a', 'A');
    $arrayFixtureCache->set('b', 'B');

    $items = iterator_to_array($arrayFixtureCache->getItems(['a', 'b', 'c']));

    expect($items['a']->isHit())->toBeTrue()
        ->and($items['a']->get())->toBe('A')
        ->and($items['b']->get())->toBe('B')
        ->and($items['c']->isHit())->toBeFalse();
});

test('deferred commit uses one bulk persistence call and retains failures', function (): void {
    $adapter = new class extends AbstractCacheAdapter
    {
        public int $bulkCalls = 0;

        #[\Override]
        public function clear(): bool
        {
            return true;
        }

        #[\Override]
        public function deleteItem(string $key): bool
        {
            return $key !== "\0";
        }

        #[\Override]
        public function deleteItems(array $keys): bool
        {
            foreach ($keys as $key) {
                if ($key === "\0") {
                    return false;
                }
            }

            return true;
        }

        #[\Override]
        public function getItem(string $key): CacheItem
        {
            return new CacheItem($this, $key);
        }

        /** @return array<string, CacheItem> */
        #[\Override]
        public function multiFetch(array $keys): array
        {
            return array_fill_keys($keys, new CacheItem($this, 'unused'));
        }

        #[\Override]
        public function hasItem(string $key): bool
        {
            return $key === "\0";
        }

        #[\Override]
        public function save(CacheItemInterface $item): bool
        {
            unset($item);

            return true;
        }

        #[\Override]
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


test('deferred state obeys PSR ordering and snapshot semantics', function (): void {
    $cache = Cache::memory('deferred-contract');
    $cache->set('overwrite', 'stored');
    $queued = $cache->getItem('overwrite')->set('queued');
    expect($cache->saveDeferred($queued))->toBeTrue()
        ->and($cache->get('overwrite'))->toBe('queued')
        ->and($cache->hasItem('overwrite'))->toBeTrue()
        ->and(iterator_to_array($cache->getItems(['overwrite']))['overwrite']->get())->toBe('queued');

    $queued->set('mutated-after-queue');
    expect($cache->get('overwrite'))->toBe('queued');

    expect($cache->set('overwrite', 'immediate'))->toBeTrue()
        ->and($cache->commit())->toBeTrue()
        ->and($cache->get('overwrite'))->toBe('immediate');

    $delete = $cache->getItem('delete')->set('queued-delete');
    expect($cache->saveDeferred($delete))->toBeTrue()
        ->and($cache->delete('delete'))->toBeTrue()
        ->and($cache->commit())->toBeTrue()
        ->and($cache->get('delete'))->toBeNull();

    $clear = $cache->getItem('clear')->set('queued-clear');
    expect($cache->saveDeferred($clear))->toBeTrue()
        ->and($cache->clear())->toBeTrue()
        ->and($cache->commit())->toBeTrue()
        ->and($cache->get('clear'))->toBeNull();
});

test('deferred null and expired values retain hit and expiry semantics', function (): void {
    $cache = Cache::memory('deferred-values');

    $null = $cache->getItem('null')->set(null);
    expect($cache->saveDeferred($null))->toBeTrue()
        ->and($cache->hasItem('null'))->toBeTrue()
        ->and($cache->get('null', 'fallback'))->toBeNull();

    $expired = $cache->getItem('expired')->set('gone')->expiresAfter(-1);
    expect($cache->saveDeferred($expired))->toBeTrue()
        ->and($cache->hasItem('expired'))->toBeFalse()
        ->and($cache->get('expired'))->toBeNull()
        ->and($cache->commit())->toBeTrue()
        ->and($cache->get('expired'))->toBeNull();
});

test('direct PSR pool rejects invalid keys and missing deletes succeed', function (): void {
    $pool = new ArrayCacheAdapter('direct-contract');
    $pool->save($pool->getItem('valid')->set('value'));

    expect(fn() => $pool->getItem('bad:key'))->toThrow(CacheInvalidArgumentException::class)
        ->and(fn() => $pool->hasItem('bad:key'))->toThrow(CacheInvalidArgumentException::class)
        ->and(fn() => $pool->deleteItem('bad:key'))->toThrow(CacheInvalidArgumentException::class)
        ->and(fn() => $pool->deleteItems(['valid', 'bad:key']))->toThrow(CacheInvalidArgumentException::class)
        ->and($pool->getItem('valid')->get())->toBe('value')
        ->and($pool->deleteItem('missing'))->toBeTrue()
        ->and($pool->deleteItems(['missing-a', 'missing-b']))->toBeTrue();
});
