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

    /**
     * @param array $keys The keys argument.
     * @phpstan-param list<string> $keys
     */
    public function deleteItems(array $keys): bool
    {
        foreach ($keys as $key) {
            unset($this->store[$this->map($key)]);
        }

        return true;
    }

    public function getItem(string $key): GenericCacheItem
    {
        $mapped = $this->map($key);
        $blob = $this->store[$mapped] ?? null;

        return $this->genericFromBlob($key, is_string($blob) ? $blob : null);
    }

    public function hasItem(string $key): bool
    {
        $mapped = $this->map($key);
        $blob = $this->store[$mapped] ?? null;
        if (!is_string($blob)) {
            return false;
        }

        $record = $this->decodeRecordFromBlob($blob);
        if ($record === null) {
            unset($this->store[$mapped]);

            return false;
        }

        return true;
    }

    /**
     * @param array $keys The keys argument.
     * @phpstan-param list<string> $keys
     * @phpstan-return array<string, GenericCacheItem>
     */
    public function multiFetch(array $keys): array
    {
        $items = [];
        foreach ($keys as $key) {
            $items[$key] = $this->getItem($key);
        }

        return $items;
    }

    public function save(CacheItemInterface $item): bool
    {
        return $this->saveEncoded($item, function (CacheItemInterface $saveItem, array $expires): bool {
            $this->store[$this->map($saveItem->getKey())] = CachePayloadCodec::encode($saveItem->get(), $expires['expiresAt']);

            return true;
        });
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
