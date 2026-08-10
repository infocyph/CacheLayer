<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Node\Adapter;

use Infocyph\CacheLayer\Cache\Adapter\AbstractCacheAdapter;
use Infocyph\CacheLayer\Cache\Adapter\InternalCachePoolInterface;
use Infocyph\CacheLayer\Cache\CacheOptions;
use Infocyph\CacheLayer\Cache\Item\CacheItem;
use Infocyph\CacheLayer\Cache\Metrics\CacheMetricsCollectorInterface;
use Infocyph\CacheLayer\Cache\Metrics\InMemoryCacheMetricsCollector;
use Psr\Cache\CacheItemInterface;
use Throwable;

final class NodeCacheAdapter extends AbstractCacheAdapter
{
    public function __construct(
        private readonly ?InternalCachePoolInterface $l1,
        private readonly NodeSqliteCacheAdapter $l2,
        private readonly bool $failOpen = true,
        private readonly CacheMetricsCollectorInterface $metrics = new InMemoryCacheMetricsCollector(),
    ) {}

    public function clear(): bool
    {
        $l2 = $this->attempt(fn(): bool => $this->l2->clear(), false, 'l2_failure');
        $l1 = $this->l1 === null || $this->attempt(fn(): bool => $this->l1->clear(), false, 'l1_failure');
        $this->deferred = [];

        return $l2 && $l1;
    }

    #[\Override]
    public function configureOptions(CacheOptions $options): void
    {
        parent::configureOptions($options);
        $this->l2->configureOptions($options);
        if ($this->l1 instanceof AbstractCacheAdapter) {
            $this->l1->configureOptions($options);
        }
    }

    public function deleteItem(string $key): bool
    {
        $l2 = $this->attempt(fn(): bool => $this->l2->deleteItem($key), false, 'l2_failure');
        $l1 = $this->l1 === null
            || $this->attempt(fn(): bool => $this->l1->deleteItem($key), false, 'l1_failure');

        return $l2 && $l1;
    }

    /** @param list<string> $keys */
    public function deleteItems(array $keys): bool
    {
        $l2 = $this->attempt(fn(): bool => $this->l2->deleteItems($keys), false, 'l2_failure');
        $l1 = $this->l1 === null
            || $this->attempt(fn(): bool => $this->l1->deleteItems($keys), false, 'l1_failure');

        return $l2 && $l1;
    }

    public function getItem(string $key): CacheItem
    {
        if ($this->l1 !== null) {
            $l1 = $this->attempt(fn(): CacheItemInterface => $this->l1->getItem($key), $this->genericMiss($key), 'l1_failure');
            if ($l1->isHit()) {
                return $this->nodeItem($l1);
            }
        }
        $l2 = $this->attempt(fn(): CacheItemInterface => $this->l2->getItem($key), $this->genericMiss($key), 'l2_failure');
        if (!$l2->isHit()) {
            return $this->genericMiss($key);
        }
        $item = $this->nodeItem($l2);
        if ($this->l1 !== null) {
            $this->saveOneInto($this->l1, $item, 'l1_failure');
        }

        return $item;
    }

    /**
     * @param list<string> $tags
     * @return array<string, int>
     */
    #[\Override]
    public function getTagVersions(array $tags): array
    {
        return $this->attempt(
            fn(): array => $this->l2->getTagVersions($tags),
            array_fill_keys($tags, 0),
            'l2_failure',
        );
    }

    public function hasItem(string $key): bool
    {
        return $this->getItem($key)->isHit();
    }

    /** @param list<string> $tags */
    #[\Override]
    public function incrementTagVersions(array $tags): bool
    {
        $l2 = $this->attempt(fn(): bool => $this->l2->incrementTagVersions($tags), false, 'l2_failure');
        $l1 = $this->l1 === null
            || $this->attempt(fn(): bool => $this->l1->incrementTagVersions($tags), false, 'l1_failure');

        return $l2 && $l1;
    }

    /**
     * @param list<string> $keys
     * @return array<string, CacheItem>
     */
    public function multiFetch(array $keys): array
    {
        [$results, $misses] = $this->readL1($keys);
        $promote = $this->readL2($misses, $results);

        if ($promote !== [] && $this->l1 !== null) {
            $this->saveInto($this->l1, $promote, 'l1_failure');
            $this->metric('l2_batch_promote', count($promote));
        }

        $ordered = [];
        foreach ($keys as $key) {
            $ordered[$key] = $results[$key] ?? $this->genericMiss($key);
        }

        return $ordered;
    }

    public function save(CacheItemInterface $item): bool
    {
        if (!$this->supportsItem($item)) {
            return false;
        }
        $stored = $this->saveOneInto($this->l2, $item, 'l2_failure');
        if (!$stored && !$this->failOpen) {
            return false;
        }
        if ($this->l1 === null) {
            return $stored;
        }

        return $this->saveOneInto($this->l1, $item, 'l1_failure') || $stored;
    }

    /** @param array<string, CacheItemInterface> $items */
    public function saveItems(array $items): bool
    {
        foreach ($items as $item) {
            if (!$this->supportsItem($item)) {
                return false;
            }
        }

        $stored = $this->saveInto($this->l2, $items, 'l2_failure');
        if (!$stored && !$this->failOpen) {
            return false;
        }
        if ($this->l1 !== null) {
            $l1Stored = $this->saveInto($this->l1, $items, 'l1_failure');

            return $stored || $l1Stored;
        }

        return $stored;
    }

    /**
     * @template T
     * @param callable(): T $operation
     * @param T $fallback
     * @return T
     */
    private function attempt(callable $operation, mixed $fallback, string $failureMetric): mixed
    {
        try {
            return $operation();
        } catch (Throwable $failure) {
            $this->metric($failureMetric);
            if (!$this->failOpen) {
                throw $failure;
            }

            return $fallback;
        }
    }

    private function metric(string $name, int $amount = 1): void
    {
        if ($amount > 0) {
            $this->metrics->increment(self::class, $name, $amount);
        }
    }

    private function nodeItem(CacheItemInterface $item): CacheItem
    {
        $ttl = $item instanceof CacheItem ? $item->ttlSeconds() : null;
        $tags = $item instanceof CacheItem ? $item->getTagVersions() : [];

        return (new CacheItem($this, $item->getKey(), $item->get(), true))
            ->expiresAfter($ttl)
            ->setTagVersions($tags);
    }

    /**
     * @param list<string> $keys
     * @return array{array<string, CacheItem>, list<string>}
     */
    private function readL1(array $keys): array
    {
        if ($this->l1 === null || $keys === []) {
            return [[], $keys];
        }

        $l1Items = $this->attempt(fn(): array => $this->readPool($this->l1, $keys), [], 'l1_failure');
        $results = [];
        $misses = [];
        foreach ($keys as $key) {
            $item = $l1Items[$key] ?? null;
            if ($item instanceof CacheItemInterface && $item->isHit()) {
                $results[$key] = $this->nodeItem($item);
            } else {
                $misses[] = $key;
            }
        }
        $this->metric('l1_batch_hit', count($keys) - count($misses));
        $this->metric('l1_batch_miss', count($misses));

        return [$results, $misses];
    }

    /**
     * @param list<string> $keys
     * @param array<string, CacheItem> $results
     * @return array<string, CacheItem>
     */
    private function readL2(array $keys, array &$results): array
    {
        if ($keys === []) {
            return [];
        }
        $items = $this->attempt(fn(): array => $this->l2->multiFetch($keys), [], 'l2_failure');
        $hits = [];
        foreach ($keys as $key) {
            $item = $items[$key] ?? null;
            if ($item instanceof CacheItemInterface && $item->isHit()) {
                $hits[$key] = $this->nodeItem($item);
                $results[$key] = $hits[$key];
            }
        }
        $this->metric('l2_batch_hit', count($hits));
        $this->metric('l2_batch_miss', count($keys) - count($hits));

        return $hits;
    }

    /**
     * @param list<string> $keys
     * @return array<string, CacheItemInterface>
     */
    private function readPool(InternalCachePoolInterface $pool, array $keys): array
    {
        $items = [];
        foreach ($pool->getItems($keys) as $key => $item) {
            if (is_string($key) && $item instanceof CacheItemInterface) {
                $items[$key] = $item;
            }
        }

        return $items;
    }

    /** @param array<string, CacheItemInterface> $items */
    private function saveInto(
        InternalCachePoolInterface $pool,
        array $items,
        string $failureMetric,
    ): bool {
        $targets = [];
        foreach ($items as $key => $item) {
            $target = $pool->createItem($key)->set($item->get());
            if ($item instanceof CacheItem) {
                $target->expiresAfter($item->ttlSeconds());
                if ($target instanceof CacheItem) {
                    $target->setTagVersions($item->getTagVersions());
                }
            }
            $targets[$key] = $target;
        }

        return $this->attempt(fn(): bool => $pool->saveItems($targets), false, $failureMetric);
    }

    private function saveOneInto(
        InternalCachePoolInterface $pool,
        CacheItemInterface $item,
        string $failureMetric,
    ): bool {
        $target = $pool->createItem($item->getKey())->set($item->get());
        if ($item instanceof CacheItem) {
            $target->expiresAfter($item->ttlSeconds());
            if ($target instanceof CacheItem) {
                $target->setTagVersions($item->getTagVersions());
            }
        }

        return $this->attempt(fn(): bool => $pool->save($target), false, $failureMetric);
    }
}
