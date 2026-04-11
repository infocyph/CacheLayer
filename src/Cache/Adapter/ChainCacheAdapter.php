<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cache\Adapter;

use Infocyph\CacheLayer\Cache\Item\GenericCacheItem;
use InvalidArgumentException;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;

final class ChainCacheAdapter extends AbstractCacheAdapter
{
    /**
     * @param array<int, CacheItemPoolInterface> $pools
     */
    public function __construct(private readonly array $pools)
    {
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
            $ttl = method_exists($item, 'ttlSeconds') ? $item->ttlSeconds() : null;

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

    public function multiFetch(array $keys): array
    {
        $items = [];
        foreach ($keys as $key) {
            $items[(string) $key] = $this->getItem((string) $key);
        }

        return $items;
    }

    public function save(CacheItemInterface $item): bool
    {
        if (!$this->supportsItem($item)) {
            return false;
        }

        $expires = CachePayloadCodec::expirationFromItem($item);
        if ($expires['ttl'] === 0) {
            return $this->deleteItem($item->getKey());
        }

        $ok = true;
        foreach ($this->pools as $pool) {
            $target = $pool->getItem($item->getKey());
            $target->set($item->get());
            $target->expiresAfter($expires['ttl']);
            $ok = $pool->save($target) && $ok;
        }

        return $ok;
    }

    protected function supportsItem(CacheItemInterface $item): bool
    {
        return $item instanceof GenericCacheItem;
    }
}
