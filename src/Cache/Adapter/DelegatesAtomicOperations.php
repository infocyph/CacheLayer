<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cache\Adapter;

use Infocyph\CacheLayer\Cache\Item\CacheItem;
use Psr\Cache\CacheItemInterface;

/** @internal Shared authoritative-only composite atomic protocol. */
trait DelegatesAtomicOperations
{
    /**
     * @throws \Infocyph\CacheLayer\Exceptions\CacheInvalidArgumentException
     * @throws \Psr\Cache\InvalidArgumentException
     */
    public function atomicCompareAndSet(string $key, mixed $expected, CacheItemInterface $replacement): bool
    {
        $pool = $this->atomicAuthority();

        return $pool !== null && $this->supportsItem($replacement)
            && $pool->atomicCompareAndSet($key, $expected, $this->atomicTarget($pool, $replacement));
    }

    /**
     * @throws \Infocyph\CacheLayer\Exceptions\CacheInvalidArgumentException
     * @throws \Psr\Cache\InvalidArgumentException
     */
    public function atomicGetAndDelete(string $key): CacheItemInterface
    {
        $this->discardDeferredKey($key);
        $pool = $this->atomicAuthority();
        if ($pool === null) {
            return $this->genericMiss($key);
        }
        $item = $pool->atomicGetAndDelete($key);

        return $item->isHit() ? $this->copyAtomicItem($item) : $this->genericMiss($key);
    }

    /**
     * @throws \Infocyph\CacheLayer\Exceptions\CacheInvalidArgumentException
     * @throws \Psr\Cache\InvalidArgumentException
     */
    public function atomicSetIfAbsent(CacheItemInterface $item): bool
    {
        $pool = $this->atomicAuthority();

        return $pool !== null && $this->supportsItem($item)
            && $pool->atomicSetIfAbsent($this->atomicTarget($pool, $item));
    }

    public function supportsAtomicCache(): bool
    {
        return $this->atomicAuthority() !== null;
    }

    /**
     * @throws \Infocyph\CacheLayer\Exceptions\CacheInvalidArgumentException
     * @throws \Psr\Cache\InvalidArgumentException
     */
    private function atomicTarget(InternalCachePoolInterface $pool, CacheItemInterface $item): CacheItemInterface
    {
        $target = $pool->createItem($item->getKey())->set($item->get());
        if ($target instanceof CacheItem && $item instanceof CacheItem) {
            $target->expiresAt(CachePayloadCodec::toDateTime($item->expirationTimestamp()))
                ->setTagGenerations($item->getTagGenerations());
        }

        return $target;
    }

    /** @throws \Infocyph\CacheLayer\Exceptions\CacheInvalidArgumentException */
    private function copyAtomicItem(CacheItemInterface $source): CacheItem
    {
        $expiresAt = $source instanceof CacheItem ? $source->expirationTimestamp() : null;
        $tags = $source instanceof CacheItem ? $source->getTagGenerations() : [];

        return new CacheItem($this, $source->getKey(), $source->get(), true)
            ->expiresAt(CachePayloadCodec::toDateTime($expiresAt))
            ->setTagGenerations($tags);
    }
}
