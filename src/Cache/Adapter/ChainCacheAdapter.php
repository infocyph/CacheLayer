<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cache\Adapter;

use Infocyph\CacheLayer\Cache\Item\AbstractCacheItem;
use Infocyph\CacheLayer\Cache\Item\GenericCacheItem;
use InvalidArgumentException;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;

final class ChainCacheAdapter extends AbstractCacheAdapter
{
    /**
     * @param array $pools The pools argument.
     * @param bool $writeToL1 The write to l1 argument.
     * @phpstan-param array<int, CacheItemPoolInterface> $pools
     */
    public function __construct(
        private readonly array $pools,
        private readonly bool $writeToL1 = true,
    ) {
        if ($pools === []) {
            throw new InvalidArgumentException('ChainCacheAdapter requires at least one pool.');
        }
    }

    public function clear(): bool
    {
        $ok = true;
        foreach ($this->pools as $pool) {
            $ok = $pool->clear() && $ok;
        }

        $this->deferred = [];

        return $ok;
    }

    public function count(): int
    {
        $first = $this->pools[0];

        return $first instanceof \Countable ? count($first) : 0;
    }

    public function deleteItem(string $key): bool
    {
        $ok = true;
        foreach ($this->pools as $pool) {
            $ok = $pool->deleteItem($key) && $ok;
        }

        return $ok;
    }

    /**
     * @param array $keys The keys argument.
     * @phpstan-param list<string> $keys
     */
    public function deleteItems(array $keys): bool
    {
        $ok = true;
        foreach ($this->pools as $pool) {
            $ok = $pool->deleteItems($keys) && $ok;
        }

        return $ok;
    }

    public function getItem(string $key): GenericCacheItem
    {
        foreach ($this->pools as $idx => $pool) {
            $item = $pool->getItem($key);
            if (!$item->isHit()) {
                continue;
            }

            $value = $item->get();
            $ttl = $item instanceof AbstractCacheItem ? $item->ttlSeconds() : null;

            for ($i = 0; $i < $idx; $i++) {
                $promote = $this->pools[$i]->getItem($key);
                $promote->set($value);
                $promote->expiresAfter($ttl);
                $this->pools[$i]->save($promote);
            }

            $out = new GenericCacheItem($this, $key);
            $out->set($value);
            $out->expiresAfter($ttl);

            return $out;
        }

        return new GenericCacheItem($this, $key);
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
        return $this->saveEncoded($item, function (CacheItemInterface $saveItem, array $expires): bool {
            $ok = true;
            $poolCount = count($this->pools);
            $start = $this->writeToL1 || $poolCount === 1 ? 0 : 1;
            for ($idx = $start; $idx < $poolCount; $idx++) {
                $pool = $this->pools[$idx];
                $target = $pool->getItem($saveItem->getKey());
                $target->set($saveItem->get());
                $target->expiresAfter($expires['ttl']);
                $ok = $pool->save($target) && $ok;
            }

            return $ok;
        });
    }

    protected function supportsItem(CacheItemInterface $item): bool
    {
        return $item instanceof GenericCacheItem;
    }
}
