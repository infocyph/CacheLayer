<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cache\Adapter;

use Infocyph\CacheLayer\Cache\Item\GenericCacheItem;
use Psr\Cache\CacheItemInterface;

final class ArrayCacheAdapter extends AbstractCacheAdapter
{
    private readonly string $ns;
    /** @var array<string, string> */
    private array $store = [];

    public function __construct(string $namespace = 'default')
    {
        $this->ns = sanitize_cache_ns($namespace);
    }

    public function clear(): bool
    {
        $this->store = [];
        $this->deferred = [];
        return true;
    }

    public function count(): int
    {
        $this->pruneExpired();
        return count($this->store);
    }

    public function deleteItem(string $key): bool
    {
        unset($this->store[$this->map($key)]);
        return true;
    }

    public function deleteItems(array $keys): bool
    {
        foreach ($keys as $key) {
            unset($this->store[$this->map((string) $key)]);
        }

        return true;
    }

    public function getItem(string $key): GenericCacheItem
    {
        $mapped = $this->map($key);
        $blob = $this->store[$mapped] ?? null;
        if (!is_string($blob)) {
            return new GenericCacheItem($this, $key);
        }

        $record = CachePayloadCodec::decode($blob);
        if ($record === null || CachePayloadCodec::isExpired($record['expires'])) {
            unset($this->store[$mapped]);
            return new GenericCacheItem($this, $key);
        }

        $item = new GenericCacheItem($this, $key);
        $item->set($record['value']);
        if ($record['expires'] !== null) {
            $item->expiresAt(CachePayloadCodec::toDateTime($record['expires']));
        }

        return $item;
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

        $this->store[$this->map($item->getKey())] = CachePayloadCodec::encode($item->get(), $expires['expiresAt']);
        return true;
    }

    protected function supportsItem(CacheItemInterface $item): bool
    {
        return $item instanceof GenericCacheItem;
    }

    private function map(string $key): string
    {
        return $this->ns . ':' . $key;
    }

    private function pruneExpired(): void
    {
        foreach ($this->store as $mapped => $blob) {
            $record = CachePayloadCodec::decode($blob);
            if ($record === null || CachePayloadCodec::isExpired($record['expires'])) {
                unset($this->store[$mapped]);
            }
        }
    }
}
