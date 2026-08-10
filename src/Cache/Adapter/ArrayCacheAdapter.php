<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cache\Adapter;

use Infocyph\CacheLayer\Cache\Item\CacheItem;
use Psr\Cache\CacheItemInterface;

final class ArrayCacheAdapter extends AbstractCacheAdapter
{
    private readonly string $ns;

    /** @var array<string, int> */
    private array $metadata = [];

    /** @var array<string, string> */
    private array $store = [];

    public function __construct(string $namespace = 'default')
    {
        $this->ns = sanitize_cache_ns($namespace);
    }

    public function clear(): bool
    {
        $this->store = [];
        $this->metadata = [];
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

    public function getItem(string $key): CacheItem
    {
        $mapped = $this->map($key);
        $blob = $this->store[$mapped] ?? null;

        return $this->genericFromBlob($key, is_string($blob) ? $blob : null);
    }

    /** @param list<string> $tags */
    #[\Override]
    public function getTagVersions(array $tags): array
    {
        $versions = [];
        foreach ($tags as $tag) {
            $versions[$tag] = $this->metadata[$tag] ?? 0;
        }

        return $versions;
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

    /** @param list<string> $tags */
    #[\Override]
    public function incrementTagVersions(array $tags): bool
    {
        foreach ($tags as $tag) {
            $this->metadata[$tag] = ($this->metadata[$tag] ?? 0) + 1;
        }

        return true;
    }

    /**
     * @param array $keys The keys argument.
     * @phpstan-param list<string> $keys
     * @phpstan-return array<string, CacheItem>
     */
    public function multiFetch(array $keys): array
    {
        $items = [];
        foreach ($keys as $key) {
            $mapped = $this->map($key);
            $blob = $this->store[$mapped] ?? null;
            $items[$key] = $this->genericFromBlobWithInvalidator(
                $key,
                is_string($blob) ? $blob : null,
                function () use ($mapped): bool {
                    unset($this->store[$mapped]);

                    return true;
                },
            );
        }

        return $items;
    }

    public function save(CacheItemInterface $item): bool
    {
        return $this->saveEncoded($item, function (CacheItemInterface $saveItem, array $expires): bool {
            $this->store[$this->map($saveItem->getKey())] = $this->encodeItem($saveItem, $expires['expiresAt']);

            return true;
        });
    }

    /** @param array<string, CacheItemInterface> $items */
    public function saveItems(array $items): bool
    {
        if (!$this->supportsItems($items)) {
            return false;
        }

        $now = time();
        foreach ($items as $item) {
            $expiration = CachePayloadCodec::expirationFromItem($item);
            if ($expiration['ttl'] !== null && $expiration['ttl'] <= 0) {
                unset($this->store[$this->map($item->getKey())]);

                continue;
            }
            $expiresAt = $expiration['ttl'] === null ? null : $now + $expiration['ttl'];
            $this->store[$this->map($item->getKey())] = $this->encodeItem($item, $expiresAt);
        }

        return true;
    }

    private function map(string $key): string
    {
        return $this->ns . ':d:' . $key;
    }

    private function pruneExpired(): void
    {
        foreach ($this->store as $mapped => $blob) {
            $record = $this->decodeRecordFromBlob($blob);
            if ($record === null) {
                unset($this->store[$mapped]);
            }
        }
    }
}
