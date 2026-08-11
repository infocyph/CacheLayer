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

    public function clear(): bool
    {
        $cleared = true;
        foreach ($this->pools as $pool) {
            $cleared = $pool->clear() && $cleared;
        }
        $this->deferred = [];

        return $cleared;
    }

    #[\Override]
    public function configureOptions(CacheOptions $options): void
    {
        parent::configureOptions($options);
        foreach ($this->pools as $pool) {
            if ($pool instanceof AbstractCacheAdapter) {
                $pool->configureOptions($options);
            }
        }
    }

    public function deleteItem(string $key): bool
    {
        $deleted = true;
        foreach ($this->pools as $pool) {
            $deleted = $pool->deleteItem($key) && $deleted;
        }

        return $deleted;
    }

    /** @param list<string> $keys */
    public function deleteItems(array $keys): bool
    {
        $deleted = true;
        foreach ($this->pools as $pool) {
            $deleted = $pool->deleteItems($keys) && $deleted;
        }

        return $deleted;
    }

    public function getItem(string $key): CacheItem
    {
        foreach ($this->pools as $index => $pool) {
            $item = $pool->getItem($key);
            if (!$item->isHit()) {
                continue;
            }
            $out = $this->copyItem($item);
            if ($index > 0) {
                $this->promoteOne($out, $index);
            }

            return $out;
        }

        return $this->genericMiss($key);
    }

    /**
     * @param list<string> $tags
     * @return array<string, string>
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
        $remaining = array_fill_keys($keys, true);
        $results = [];
        foreach ($this->pools as $index => $pool) {
            if ($remaining === []) {
                break;
            }
            $wanted = array_keys($remaining);
            $fetched = $pool->multiFetch($wanted);
            $hits = [];
            foreach ($wanted as $key) {
                $item = $fetched[$key] ?? null;
                if (!$item instanceof CacheItemInterface || !$item->isHit()) {
                    continue;
                }
                $hits[$key] = $this->copyItem($item);
                $results[$key] = $hits[$key];
                unset($remaining[$key]);
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

        $written = true;
        $start = $this->writeToL1 || count($this->pools) === 1 ? 0 : 1;
        for ($index = $start, $count = count($this->pools); $index < $count; $index++) {
            $written = $this->saveOneIntoPool($this->pools[$index], $item) && $written;
        }

        return $written;
    }

    /** @param array<string, CacheItemInterface> $items */
    public function saveItems(array $items): bool
    {
        foreach ($items as $item) {
            if (!$this->supportsItem($item)) {
                return false;
            }
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

    /** @param array<string, CacheItem> $items */
    private function promote(array $items, int $tierIndex): void
    {
        for ($index = 0; $index < $tierIndex; $index++) {
            if ($this->saveIntoPool($this->pools[$index], $items)) {
                $this->metrics->increment(self::class, 'promotion_batch');
                $this->metrics->increment(self::class, 'promotion_keys', count($items));
            }
        }
    }

    private function promoteOne(CacheItemInterface $item, int $tierIndex): void
    {
        for ($index = 0; $index < $tierIndex; $index++) {
            $this->saveOneIntoPool($this->pools[$index], $item);
        }
    }

    /** @param array<string, CacheItemInterface> $items */
    private function saveIntoPool(InternalCachePoolInterface $pool, array $items): bool
    {
        $targets = [];
        foreach ($items as $key => $item) {
            $target = $pool->createItem($key);
            $target->set($item->get());
            $target->expiresAfter($item instanceof CacheItem ? $item->ttlSeconds() : null);
            if ($target instanceof CacheItem && $item instanceof CacheItem) {
                $target->setTagGenerations($item->getTagGenerations());
            }
            $targets[$key] = $target;
        }

        return $pool->saveItems($targets);
    }

    private function saveOneIntoPool(InternalCachePoolInterface $pool, CacheItemInterface $item): bool
    {
        $target = $pool->createItem($item->getKey());
        $target->set($item->get());
        $target->expiresAfter($item instanceof CacheItem ? $item->ttlSeconds() : null);
        if ($target instanceof CacheItem && $item instanceof CacheItem) {
            $target->setTagGenerations($item->getTagGenerations());
        }

        return $pool->save($target);
    }

    /** @param array<string, CacheItemInterface> $items */
    private function writeBatch(array $items): bool
    {
        $written = true;
        $start = $this->writeToL1 || count($this->pools) === 1 ? 0 : 1;
        for ($index = $start, $count = count($this->pools); $index < $count; $index++) {
            $written = $this->saveIntoPool($this->pools[$index], $items) && $written;
        }

        return $written;
    }
}
