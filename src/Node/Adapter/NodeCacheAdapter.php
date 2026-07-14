<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Node\Adapter;

use Infocyph\CacheLayer\Cache\Adapter\AbstractCacheAdapter;
use Infocyph\CacheLayer\Cache\Adapter\CachePayloadCodec;
use Infocyph\CacheLayer\Cache\Item\GenericCacheItem;
use Infocyph\CacheLayer\Cache\Metrics\CacheMetricsCollectorInterface;
use Infocyph\CacheLayer\Cache\Metrics\InMemoryCacheMetricsCollector;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Throwable;

final class NodeCacheAdapter extends AbstractCacheAdapter
{
    public function __construct(
        private readonly ?CacheItemPoolInterface $l1,
        private readonly NodeSqliteCacheAdapter $l2,
        private readonly bool $failOpen = true,
        private readonly CacheMetricsCollectorInterface $metrics = new InMemoryCacheMetricsCollector(),
    ) {}

    public function clear(): bool
    {
        return $this->runAcrossLayers(static fn(CacheItemPoolInterface $pool): bool => $pool->clear());
    }

    #[\Override]
    public function commit(): bool
    {
        $items = array_values($this->deferred);
        if ($items === []) {
            return true;
        }

        try {
            $stored = $this->l2->saveMany($items);
        } catch (Throwable $exception) {
            if (!$this->failOpen) {
                throw $exception;
            }

            if (!$this->saveAllToL1($items)) {
                return false;
            }

            $this->deferred = [];

            return true;
        }

        if (!$stored) {
            return false;
        }

        $this->deferred = [];
        if ($this->l1 === null) {
            return true;
        }

        $this->saveAllToL1($items);

        return true;
    }

    public function count(): int
    {
        try {
            return count($this->l2);
        } catch (Throwable $exception) {
            if (!$this->failOpen) {
                throw $exception;
            }

            $this->metric('sqlite_failure');

            return 0;
        }
    }

    public function deleteItem(string $key): bool
    {
        return $this->runAcrossLayers(static fn(CacheItemPoolInterface $pool): bool => $pool->deleteItem($key));
    }

    /**
     * @param array $keys The keys argument.
     * @phpstan-param list<string> $keys
     */
    public function deleteItems(array $keys): bool
    {
        return $this->runAcrossLayers(static fn(CacheItemPoolInterface $pool): bool => $pool->deleteItems($keys));
    }

    public function getItem(string $key): GenericCacheItem
    {
        $l1Item = $this->itemFromL1($key);
        if ($l1Item !== null) {
            return $l1Item;
        }

        try {
            $l2Item = $this->l2->getItem($key);
        } catch (Throwable $exception) {
            if (!$this->failOpen) {
                throw $exception;
            }

            $this->metric('sqlite_failure');

            return new GenericCacheItem($this, $key);
        }

        if (!$l2Item->isHit()) {
            $this->metric('sqlite_miss');

            return new GenericCacheItem($this, $key);
        }

        $this->metric('sqlite_hit');
        $this->saveToL1($l2Item);

        return $this->nodeItem($l2Item);
    }

    public function hasItem(string $key): bool
    {
        return $this->getItem($key)->isHit();
    }

    /**
     * @param array $keys The keys argument.
     * @phpstan-param list<string> $keys
     * @phpstan-return array<string, GenericCacheItem>
     */
    public function multiFetch(array $keys): array
    {
        return $this->multiFetchItems($keys, $this->getItem(...));
    }

    public function save(CacheItemInterface $item): bool
    {
        if (!$this->supportsItem($item)) {
            return false;
        }

        try {
            $stored = $this->l2->save($item);
        } catch (Throwable $exception) {
            if (!$this->failOpen) {
                throw $exception;
            }

            $this->metric('sqlite_failure');

            return $this->saveToL1($item);
        }

        if (!$stored) {
            $this->metric('write_failure');

            return false;
        }

        $this->metric('write_success');
        $this->saveToL1($item);

        return true;
    }

    protected function supportsItem(CacheItemInterface $item): bool
    {
        return $item instanceof GenericCacheItem;
    }

    private function itemFromL1(string $key): ?GenericCacheItem
    {
        if ($this->l1 === null) {
            return null;
        }

        try {
            $item = $this->l1->getItem($key);
        } catch (Throwable $exception) {
            if (!$this->failOpen) {
                throw $exception;
            }

            $this->metric('apcu_failure');

            return null;
        }

        if (!$item->isHit()) {
            $this->metric('apcu_miss');

            return null;
        }

        $this->metric('apcu_hit');

        return $this->nodeItem($item);
    }

    private function metric(string $name): void
    {
        $this->metrics->increment(self::class, $name);
    }

    private function nodeItem(CacheItemInterface $item): GenericCacheItem
    {
        $nodeItem = new GenericCacheItem($this, $item->getKey(), $item->get(), true);
        $expires = CachePayloadCodec::expirationFromItem($item);
        if ($expires['ttl'] !== null) {
            $nodeItem->expiresAfter($expires['ttl']);
        }

        return $nodeItem;
    }

    private function runAcrossLayers(callable $operation): bool
    {
        $success = false;
        $failure = null;

        foreach ([$this->l2, $this->l1] as $pool) {
            if (!$pool instanceof CacheItemPoolInterface) {
                continue;
            }

            try {
                $success = $operation($pool) || $success;
            } catch (Throwable $exception) {
                $failure ??= $exception;
            }
        }

        if ($failure !== null && !$this->failOpen) {
            throw $failure;
        }

        return $success;
    }

    /**
     * @param array $items The items argument.
     * @phpstan-param list<CacheItemInterface> $items
     */
    private function saveAllToL1(array $items): bool
    {
        $success = true;
        foreach ($items as $item) {
            $success = $this->saveToL1($item) && $success;
        }

        return $success;
    }

    private function saveToL1(CacheItemInterface $item): bool
    {
        if ($this->l1 === null) {
            return true;
        }

        try {
            $target = $this->l1->getItem($item->getKey());
            $target->set($item->get());
            $expires = CachePayloadCodec::expirationFromItem($item);
            $target->expiresAfter($expires['ttl']);
            $stored = $this->l1->save($target);
        } catch (Throwable $exception) {
            if (!$this->failOpen) {
                throw $exception;
            }

            $this->metric('apcu_failure');

            return false;
        }

        $this->metric($stored ? 'promotion_success' : 'promotion_failure');

        return $stored;
    }
}
