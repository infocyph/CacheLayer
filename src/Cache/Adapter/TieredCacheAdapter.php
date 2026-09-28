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
    private bool $bypassUpperTier = false;

    /** @var array<string, true> */
    private array $bypassedKeys = [];

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
            if ($index === 0 && !$poolCleared) {
                $this->bypassUpperTier = true;
            }
            $cleared = $poolCleared && $cleared;
        }
        if ($cleared) {
            $this->bypassUpperTier = false;
            $this->bypassedKeys = [];
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
            if ($index === 0) {
                $this->setUpperTierFence($key, !$poolDeleted);
            }
            $deleted = $poolDeleted && $deleted;
        }

        return $deleted;
    }

    /** @param list<string> $keys */
    public function deleteItems(array $keys): bool
    {
        $deleted = true;
        foreach ($this->pools as $index => $pool) {
            $poolDeleted = $pool->deleteItems($keys);
            if ($index === 0) {
                foreach ($keys as $key) {
                    $this->setUpperTierFence($key, !$poolDeleted);
                }
            }
            $deleted = $poolDeleted && $deleted;
        }

        return $deleted;
    }

    public function getItem(string $key): CacheItem
    {
        foreach ($this->pools as $index => $pool) {
            if ($this->shouldBypass($index, $key)) {
                continue;
            }

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
        $remaining = $keys;
        $results = [];
        foreach ($this->pools as $index => $pool) {
            if ($remaining === []) {
                break;
            }

            $wanted = [];
            foreach ($remaining as $key) {
                if (!$this->shouldBypass($index, $key)) {
                    $wanted[] = $key;
                }
            }
            if ($wanted === []) {
                continue;
            }

            $fetched = $pool->multiFetch($wanted);
            $hits = [];
            $next = [];
            foreach ($remaining as $key) {
                if ($this->shouldBypass($index, $key)) {
                    $next[] = $key;

                    continue;
                }

                $item = $fetched[$key] ?? null;
                if (!$item instanceof CacheItemInterface || !$item->isHit()) {
                    $next[] = $key;

                    continue;
                }
                $hits[$key] = $this->copyItem($item);
                $results[$key] = $hits[$key];
            }
            $remaining = $next;
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

        if ($start === 0) {
            if ($written) {
                $this->setUpperTierFence($item->getKey(), false);
            }

            return $written;
        }

        $invalidated = $this->pools[0]->deleteItem($item->getKey());
        $this->setUpperTierFence($item->getKey(), !$invalidated);

        return $written && $invalidated;
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
            if ($index === 0 && $this->bypassUpperTier) {
                continue;
            }

            $promotable = [];
            foreach ($items as $item) {
                if (!$this->shouldBypass($index, $item->getKey())) {
                    $promotable[$item->getKey()] = $item;
                }
            }
            if ($promotable === []) {
                continue;
            }

            if ($this->saveIntoPool($this->pools[$index], $promotable)) {
                $this->metrics->increment(self::class, 'promotion_batch');
                $this->metrics->increment(self::class, 'promotion_keys', count($promotable));
                foreach ($promotable as $item) {
                    $this->setUpperTierFence($item->getKey(), false);
                }
            } elseif ($index === 0) {
                foreach ($promotable as $item) {
                    $this->setUpperTierFence($item->getKey(), true);
                }
            }
        }
    }

    private function promoteOne(CacheItemInterface $item, int $tierIndex): void
    {
        for ($index = 0; $index < $tierIndex; $index++) {
            if ($this->shouldBypass($index, $item->getKey())) {
                continue;
            }

            $stored = $this->saveOneIntoPool($this->pools[$index], $item);
            if ($index === 0) {
                $this->setUpperTierFence($item->getKey(), !$stored);
            }
        }
    }

    /** @param array<string, CacheItemInterface> $items */
    private function saveIntoPool(InternalCachePoolInterface $pool, array $items): bool
    {
        $targets = [];
        foreach ($items as $item) {
            $key = $item->getKey();
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

    private function setUpperTierFence(string $key, bool $fenced): void
    {
        $identity = hash('xxh128', $key);
        if ($fenced) {
            $this->bypassedKeys[$identity] = true;

            return;
        }

        unset($this->bypassedKeys[$identity]);
    }

    private function shouldBypass(int $tierIndex, string $key): bool
    {
        return $tierIndex === 0
            && ($this->bypassUpperTier || isset($this->bypassedKeys[hash('xxh128', $key)]));
    }

    /** @param array<string, CacheItemInterface> $items */
    private function writeBatch(array $items): bool
    {
        $written = true;
        $start = $this->writeToL1 || count($this->pools) === 1 ? 0 : 1;
        for ($index = $start, $count = count($this->pools); $index < $count; $index++) {
            $written = $this->saveIntoPool($this->pools[$index], $items) && $written;
        }

        if ($start === 0) {
            if ($written) {
                foreach ($items as $item) {
                    $this->setUpperTierFence($item->getKey(), false);
                }
            }

            return $written;
        }

        $keys = [];
        foreach ($items as $item) {
            $keys[] = $item->getKey();
        }
        $invalidated = $this->pools[0]->deleteItems($keys);
        foreach ($keys as $key) {
            $this->setUpperTierFence($key, !$invalidated);
        }

        return $written && $invalidated;
    }
}
