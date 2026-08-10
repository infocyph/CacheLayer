<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Tests\Cache;

use Infocyph\CacheLayer\Cache\Adapter\AbstractCacheAdapter;
use Infocyph\CacheLayer\Cache\Adapter\CachePayloadCodec;
use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\CacheLayer\Cache\CacheOptions;
use Infocyph\CacheLayer\Cache\Item\CacheItem;
use Infocyph\CacheLayer\Exceptions\CacheInvalidArgumentException;
use Psr\Cache\CacheItemInterface;
use RuntimeException;

final class ArchitectureHardeningTest extends AbstractCacheAdapter
{
    /** @var array<string, array{value:mixed,expires:int|null,tags:array<string,int>}> */
    private array $records = [];

    /** @var array<string, int> */
    private array $versions = [];

    public int $deleteBatches = 0;

    public int $readBatches = 0;

    public int $saveBatches = 0;

    public int $tagFetchBatches = 0;

    public bool $throwOnRead = false;

    public bool $throwOnTagRead = false;

    public function clear(): bool
    {
        $this->records = [];
        $this->versions = [];

        return true;
    }

    public function deleteItem(string $key): bool
    {
        return $this->deleteItems([$key]);
    }

    public function deleteItems(array $keys): bool
    {
        $this->deleteBatches++;
        foreach ($keys as $key) {
            unset($this->records[$key]);
        }

        return true;
    }

    public function getItem(string $key): CacheItem
    {
        return $this->multiFetch([$key])[$key];
    }

    public function hasItem(string $key): bool
    {
        return $this->getItem($key)->isHit();
    }

    /** @return array<string, CacheItem> */
    public function multiFetch(array $keys): array
    {
        $this->readBatches++;
        if ($this->throwOnRead) {
            throw new RuntimeException('backend read failed');
        }
        $items = [];
        foreach ($keys as $key) {
            $record = $this->records[$key] ?? null;
            if ($record === null || CachePayloadCodec::isExpired($record['expires'])) {
                $items[$key] = new CacheItem($this, $key);

                continue;
            }
            $items[$key] = (new CacheItem($this, $key, $record['value'], true))
                ->expiresAt(CachePayloadCodec::toDateTime($record['expires']))
                ->setTagVersions($record['tags']);
        }

        return $items;
    }

    public function save(CacheItemInterface $item): bool
    {
        return $this->saveItems([$item->getKey() => $item]);
    }

    public function saveItems(array $items): bool
    {
        $this->saveBatches++;
        foreach ($items as $item) {
            if (!$this->supportsItem($item)) {
                return false;
            }
        }
        foreach ($items as $item) {
            $ttl = $item instanceof CacheItem ? $item->ttlSeconds() : null;
            if ($ttl !== null && $ttl <= 0) {
                unset($this->records[$item->getKey()]);

                continue;
            }
            $this->records[$item->getKey()] = [
                'value' => $item->get(),
                'expires' => $ttl === null ? null : time() + $ttl,
                'tags' => $item instanceof CacheItem ? $item->getTagVersions() : [],
            ];
        }

        return true;
    }

    public function getTagVersions(array $tags): array
    {
        $this->tagFetchBatches++;
        if ($this->throwOnTagRead) {
            throw new RuntimeException('backend tag read failed');
        }
        $versions = [];
        foreach ($tags as $tag) {
            $versions[$tag] = $this->versions[$tag] ?? 0;
        }

        return $versions;
    }

    public function incrementTagVersions(array $tags): bool
    {
        foreach ($tags as $tag) {
            $this->versions[$tag] = ($this->versions[$tag] ?? 0) + 1;
        }

        return true;
    }

    public function resetOperationCounts(): void
    {
        $this->deleteBatches = 0;
        $this->readBatches = 0;
        $this->saveBatches = 0;
        $this->tagFetchBatches = 0;
    }
}

test('facade bulk methods call one adapter bulk path', function () {
    $adapter = new ArchitectureHardeningTest();
    $cache = new Cache($adapter);

    expect($cache->setMultiple(['a' => 1, 'b' => 2, 'c' => 3]))->toBeTrue()
        ->and($adapter->saveBatches)->toBe(1);

    $adapter->resetOperationCounts();
    expect($cache->getMultiple(['c', 'missing', 'a'], 'default'))
        ->toBe(['c' => 3, 'missing' => 'default', 'a' => 1])
        ->and($adapter->readBatches)->toBe(1);

    expect($cache->deleteMultiple(['a', 'b']))->toBeTrue()
        ->and($adapter->deleteBatches)->toBe(1);
});

test('bulk validation completes before any storage mutation', function () {
    $adapter = new ArchitectureHardeningTest();
    $cache = new Cache($adapter);

    expect(fn() => $cache->setMultiple(['valid' => 1, 'bad key' => 2]))
        ->toThrow(CacheInvalidArgumentException::class)
        ->and($adapter->saveBatches)->toBe(0);
    expect(fn() => $cache->setMultiple([1 => 'numeric key']))
        ->toThrow(CacheInvalidArgumentException::class)
        ->and($adapter->saveBatches)->toBe(0);
    expect(fn() => $cache->deleteMultiple(['valid', 'bad:key']))
        ->toThrow(CacheInvalidArgumentException::class)
        ->and($adapter->deleteBatches)->toBe(0);
});

test('bulk tagged reads fetch tag versions once and reject whole stale records', function () {
    $adapter = new ArchitectureHardeningTest();
    $cache = new Cache($adapter);
    $cache->setTagged('one', 1, ['group', 'shared']);
    $cache->setTagged('two', 2, ['group']);
    $adapter->resetOperationCounts();

    expect($cache->getMultiple(['one', 'two']))->toBe(['one' => 1, 'two' => 2])
        ->and($adapter->tagFetchBatches)->toBe(1);

    $cache->invalidateTag('group');
    $adapter->resetOperationCounts();
    expect($cache->getMultiple(['one', 'two']))->toBe(['one' => null, 'two' => null])
        ->and($adapter->tagFetchBatches)->toBe(1)
        ->and($adapter->deleteBatches)->toBe(1);
});

test('cache items can only be persisted by their exact owning pool', function () {
    $first = new ArchitectureHardeningTest();
    $second = new ArchitectureHardeningTest();
    $foreign = $first->createItem('owned')->set('value');
    $local = $second->createItem('local')->set('local-value');

    expect($second->save($foreign))->toBeFalse()
        ->and($second->saveDeferred($foreign))->toBeFalse()
        ->and($second->saveItems(['local' => $local, 'owned' => $foreign]))->toBeFalse()
        ->and($second->getItem('local')->isHit())->toBeFalse();
});

test('zero and negative ttl delete through single and bulk APIs', function () {
    $cache = new Cache(new ArchitectureHardeningTest());
    $cache->setMultiple(['zero' => 1, 'negative' => 2]);

    expect($cache->set('zero', 3, 0))->toBeTrue()
        ->and($cache->setMultiple(['negative' => 4], -1))->toBeTrue()
        ->and($cache->getMultiple(['zero', 'negative']))
        ->toBe(['zero' => null, 'negative' => null]);
});

test('runtime failures are fail-open by default and optionally propagate', function () {
    $openAdapter = new ArchitectureHardeningTest();
    $open = new Cache($openAdapter);
    $openAdapter->throwOnRead = true;

    expect($open->get('key', 'fallback'))->toBe('fallback')
        ->and($open->exportMetrics()['architecture_hardening_test']['backend_failure'] ?? 0)->toBe(1);

    $closedAdapter = new ArchitectureHardeningTest();
    $closed = new Cache($closedAdapter, options: new CacheOptions(failOpen: false));
    $closedAdapter->throwOnRead = true;
    expect(fn() => $closed->get('key'))->toThrow(RuntimeException::class);
});

test('tag metadata failures cannot expose or create tagged values', function () {
    $adapter = new ArchitectureHardeningTest();
    $cache = new Cache($adapter);
    $cache->setTagged('tagged', 'value', ['group']);
    $cache->set('plain', 'plain-value');
    $adapter->resetOperationCounts();
    $adapter->throwOnTagRead = true;

    expect($cache->get('tagged', 'fallback'))->toBe('fallback')
        ->and($cache->getMultiple(['tagged', 'plain'], 'fallback'))
        ->toBe(['tagged' => 'fallback', 'plain' => 'plain-value'])
        ->and($cache->setTagged('new-tagged', 'value', ['group']))->toBeFalse()
        ->and($adapter->saveBatches)->toBe(0);
});

test('tiered reads perform one batch per needed tier and one promotion batch', function () {
    $l1 = new ArchitectureHardeningTest();
    $l2 = new ArchitectureHardeningTest();
    $cache = Cache::tiered([$l1, $l2]);
    $l1->set('l1', 1);
    $l2->set('l2a', 2);
    $l2->set('l2b', 3);
    $l1->resetOperationCounts();
    $l2->resetOperationCounts();

    expect($cache->getMultiple(['l1', 'l2a', 'missing', 'l2b']))
        ->toBe(['l1' => 1, 'l2a' => 2, 'missing' => null, 'l2b' => 3])
        ->and($l1->readBatches)->toBe(1)
        ->and($l2->readBatches)->toBe(1)
        ->and($l1->saveBatches)->toBe(1);
});
