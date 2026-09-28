<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cache\Adapter;

use Infocyph\CacheLayer\Cache\CacheOptions;
use Infocyph\CacheLayer\Cache\Item\CacheItem;
use Infocyph\CacheLayer\Cache\Metrics\CacheMetricsCollectorInterface;
use Infocyph\CacheLayer\Cache\Metrics\InMemoryCacheMetricsCollector;
use InvalidArgumentException;
use LogicException;
use Psr\Cache\CacheItemInterface;

final class TieredCacheAdapter extends AbstractCacheAdapter
{
    private bool $l1Readable = true;

    /** @param list<InternalCachePoolInterface> $pools */
    public function __construct(
        private readonly array $pools,
        private readonly bool $writeToL1 = true,
        private readonly CacheMetricsCollectorInterface $metrics = new InMemoryCacheMetricsCollector(),
    ) {
        if ($pools === []) {
            throw new InvalidArgumentException('A tiered cache requires at least one pool.');
        }
    }

    #[\Override]
    public function assertOptionsCompatible(CacheOptions $options): void
    {
        parent::assertOptionsCompatible($options);
        foreach ($this->pools as $pool) {
            if ($pool instanceof AbstractCacheAdapter) {
                $pool->assertOptionsCompatible($options);
            }
        }
    }

    #[\Override]
    public function assertStorageIdentityCompatible(string $storageIdentity): void
    {
        parent::assertStorageIdentityCompatible($storageIdentity);
        foreach ($this->pools as $pool) {
            if ($pool instanceof AbstractCacheAdapter) {
                $pool->assertStorageIdentityCompatible($storageIdentity);
            }
        }
    }

    public function clear(): bool
    {
        $cleared = true;
        foreach ($this->pools as $index => $pool) {
            $poolCleared = $pool->clear();
            $cleared = $poolCleared && $cleared;
            if ($index === 0) {
                $this->l1Readable = $poolCleared;
            }
        }
        $this->deferred = [];

        return $cleared;
    }

    #[\Override]
    public function configureOptions(CacheOptions $options): void
    {
        $this->assertOptionsCompatible($options);
        parent::configureOptions($options);
        foreach ($this->pools as $pool) {
            if ($pool instanceof AbstractCacheAdapter) {
                $pool->configureOptions($options);
            }
        }
    }

    #[\Override]
    public function configureStorageIdentity(string $storageIdentity): void
    {
        $this->assertStorageIdentityCompatible($storageIdentity);
        parent::configureStorageIdentity($storageIdentity);
        foreach ($this->pools as $pool) {
            if ($pool instanceof AbstractCacheAdapter) {
                $pool->configureStorageIdentity($storageIdentity);
            }
        }
    }

    public function deleteItem(string $key): bool
    {
        $deleted = true;
        foreach ($this->pools as $index => $pool) {
            $poolDeleted = $pool->deleteItem($key);
            $deleted = $poolDeleted && $deleted;
            if ($index === 0) {
                $this->l1Readable = $poolDeleted;
            }
        }

        return $deleted;
    }

    /** @param list<string> $keys */
    public function deleteItems(array $keys): bool
    {
        $deleted = true;
        foreach ($this->pools as $index => $pool) {
            $poolDeleted = $pool->deleteItems($keys);
            $deleted = $poolDeleted && $deleted;
            if ($index === 0) {
                $this->l1Readable = $poolDeleted;
            }
        }

        return $deleted;
    }

    public function getItem(string $key): CacheItem
    {
        foreach ($this->readablePools() as $index => $pool) {
            $item = $pool->getItem($key);
            if (!$item->isHit()) {
                continue;
            }

            $copy = $this->copyItem($item);
            if ($index > 0) {
                $this->promoteOne($copy, $index);
            }

            return $copy;
        }

        return $this->genericMiss($key);
    }

    /**
     * @param list<string> $tags
     * @return array<int|string, string>
     */
    #[\Override]
    public function getTagGenerations(array $tags): array
    {
        foreach (array_reverse($this->pools) as $authoritative) {
            return $authoritative->getTagGenerations($tags);
        }

        throw new LogicException('A tiered cache must retain an authoritative pool.');
    }

    public function hasItem(string $key): bool
    {
        return $this->getItem($key)->isHit();
    }

    /**
     * @param list<string> $keys
     * @return array<string, CacheItem>
     */
    public function multiFetch(array $keys): array
    {
        $remaining = $keys;
        $results = [];
        foreach ($this->readablePools() as $index => $pool) {
            if ($remaining === []) {
                break;
            }

            $fetched = $pool->multiFetch($remaining);
            [$hits, $remaining] = $this->extractHits($remaining, $fetched);
            foreach ($hits as $key => $item) {
                $results[$key] = $item;
            }
            if ($index > 0 && $hits !== []) {
                $this->promote($hits, $index);
            }
        }

        $ordered = [];
        foreach ($keys as $key) {
            $ordered[$key] = $results[$key] ?? $this->genericMiss($key);
        }

        return $ordered;
    }

    /** @param list<string> $tags */
    #[\Override]
    public function rotateTagGenerations(array $tags): bool
    {
        $incremented = true;
        foreach ($this->pools as $pool) {
            $incremented = $pool->rotateTagGenerations($tags) && $incremented;
        }

        return $incremented;
    }

    public function save(CacheItemInterface $item): bool
    {
        if (!$this->supportsItem($item)) {
            return false;
        }

        $start = $this->writeStart();
        $written = true;
        for ($index = $start, $count = count($this->pools); $index < $count; ++$index) {
            $written = $this->saveOneIntoPool($this->pools[$index], $item) && $written;
        }

        return $start === 0
            ? $this->finishL1Write($written)
            : $this->invalidateSkippedL1([$item->getKey()], $written);
    }

    /** @param array<string, CacheItemInterface> $items */
    public function saveItems(array $items): bool
    {
        if (!$this->supportsItems($items)) {
            return false;
        }

        return $this->writeBatch($items);
    }

    private function copyItem(CacheItemInterface $source): CacheItem
    {
        $ttl = $source instanceof CacheItem ? $source->ttlSeconds() : null;
        $tags = $source instanceof CacheItem ? $source->getTagGenerations() : [];

        return (new CacheItem($this, $source->getKey(), $source->get(), true))
            ->expiresAfter($ttl)
            ->setTagGenerations($tags);
    }

    /**
     * @param list<string> $wanted
     * @param array<string, CacheItemInterface> $fetched
     * @return array{array<string, CacheItem>, list<string>}
     */
    private function extractHits(array $wanted, array $fetched): array
    {
        $hits = [];
        $misses = [];
        foreach ($wanted as $key) {
            $item = $fetched[$key] ?? null;
            if ($item instanceof CacheItemInterface && $item->isHit()) {
                $hits[$key] = $this->copyItem($item);
            } else {
                $misses[] = $key;
            }
        }

        return [$hits, $misses];
    }

    private function finishL1Write(bool $written): bool
    {
        if ($written) {
            $this->l1Readable = true;
        }

        return $written;
    }

    /** @param list<string> $keys */
    private function invalidateSkippedL1(array $keys, bool $written): bool
    {
        if (count($this->pools) === 1) {
            return $written;
        }

        $invalidated = $this->pools[0]->deleteItems($keys);
        $this->l1Readable = $invalidated;

        return $written && $invalidated;
    }

    /** @param array<string, CacheItem> $items */
    private function promote(array $items, int $tierIndex): void
    {
        if (!$this->l1Readable) {
            return;
        }

        for ($index = 0; $index < $tierIndex; ++$index) {
            if (!$this->saveIntoPool($this->pools[$index], $items)) {
                if ($index === 0) {
                    $this->l1Readable = false;
                }

                continue;
            }
            $this->metrics->increment(self::class, 'promotion_batch');
            $this->metrics->increment(self::class, 'promotion_keys', count($items));
        }
    }

    private function promoteOne(CacheItemInterface $item, int $tierIndex): void
    {
        if (!$this->l1Readable) {
            return;
        }

        for ($index = 0; $index < $tierIndex; ++$index) {
            if (!$this->saveOneIntoPool($this->pools[$index], $item) && $index === 0) {
                $this->l1Readable = false;

                return;
            }
        }
    }

    /** @return array<int, InternalCachePoolInterface> */
    private function readablePools(): array
    {
        if ($this->l1Readable || count($this->pools) === 1) {
            return $this->pools;
        }

        $pools = $this->pools;
        unset($pools[0]);

        return $pools;
    }

    /** @param array<string, CacheItemInterface> $items */
    private function saveIntoPool(InternalCachePoolInterface $pool, array $items): bool
    {
        $targets = [];
        foreach ($items as $item) {
            $key = $item->getKey();
            $target = $pool->createItem($key)->set($item->get());
            if ($target instanceof CacheItem && $item instanceof CacheItem) {
                $target->expiresAfter($item->ttlSeconds())
                    ->setTagGenerations($item->getTagGenerations());
            }
            $targets["key:\0" . $key] = $target;
        }

        return $pool->saveItems($targets);
    }

    private function saveOneIntoPool(InternalCachePoolInterface $pool, CacheItemInterface $item): bool
    {
        $target = $pool->createItem($item->getKey())->set($item->get());
        if ($target instanceof CacheItem && $item instanceof CacheItem) {
            $target->expiresAfter($item->ttlSeconds())
                ->setTagGenerations($item->getTagGenerations());
        }

        return $pool->save($target);
    }

    /** @param array<string, CacheItemInterface> $items */
    private function writeBatch(array $items): bool
    {
        $start = $this->writeStart();
        $written = true;
        for ($index = $start, $count = count($this->pools); $index < $count; ++$index) {
            $written = $this->saveIntoPool($this->pools[$index], $items) && $written;
        }

        if ($start === 0) {
            return $this->finishL1Write($written);
        }

        $keys = array_map(
            static fn(CacheItemInterface $item): string => $item->getKey(),
            array_values($items),
        );

        return $this->invalidateSkippedL1($keys, $written);
    }

    private function writeStart(): int
    {
        return $this->writeToL1 || count($this->pools) === 1 ? 0 : 1;
    }
}
