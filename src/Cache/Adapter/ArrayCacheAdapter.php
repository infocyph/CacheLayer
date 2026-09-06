<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cache\Adapter;

use Infocyph\CacheLayer\Cache\CacheInput;
use Infocyph\CacheLayer\Cache\CacheRecord;
use Infocyph\CacheLayer\Cache\Item\CacheItem;
use Psr\Cache\CacheItemInterface;

final class ArrayCacheAdapter extends AbstractCacheAdapter implements AtomicCachePoolInterface, TagGenerationCacheInterface
{
    private readonly string $ns;

    /** @var array<string, string> */
    private array $metadata = [];

    /** @var array<string, string> */
    private array $store = [];

    public function __construct(string $namespace = 'default')
    {
        $this->ns = CacheInput::namespace($namespace);
    }

    public function atomicGetAndDelete(string $key): CacheItemInterface
    {
        $mapped = $this->map($key);
        $record = $this->atomicRecord($mapped);
        if (!$record instanceof CacheRecord) {
            return $this->genericMiss($key);
        }

        unset($this->store[$mapped]);

        return $this->genericItemFromRecord($key, $record);
    }

    public function atomicSetIfAbsent(CacheItemInterface $item): bool
    {
        if (!$this->supportsItem($item)) {
            return false;
        }

        $expiration = CachePayloadCodec::expirationFromItem($item);
        if ($expiration['ttl'] !== null && $expiration['ttl'] <= 0) {
            return false;
        }

        $mapped = $this->map($item->getKey());
        if ($this->atomicRecord($mapped) instanceof CacheRecord) {
            return false;
        }

        $this->store[$mapped] = $this->encodeItem($item, $expiration['expiresAt']);

        return true;
    }

    public function clear(): bool
    {
        $this->store = [];
        $this->metadata = [];
        $this->deferred = [];

        return true;
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

        return $this->genericFromBlobWithInvalidator(
            $key,
            is_string($blob) ? $blob : null,
            function () use ($mapped): bool {
                unset($this->store[$mapped]);

                return true;
            },
        );
    }

    /** @param list<string> $tags */
    #[\Override]
    public function getTagGenerations(array $tags): array
    {
        $generations = [];
        foreach ($tags as $tag) {
            $generations[$tag] = $this->metadata[$tag] ??= self::newGeneration();
        }

        return $generations;
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

    /** @param list<string> $tags */
    #[\Override]
    public function readTagGenerations(array $tags): array
    {
        return array_intersect_key($this->metadata, array_fill_keys($tags, true));
    }

    /** @param list<string> $tags */
    #[\Override]
    public function rotateTagGenerations(array $tags): bool
    {
        foreach ($tags as $tag) {
            $this->metadata[$tag] = self::newGeneration();
        }

        return true;
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

    /** @param array<string, string> $generations */
    #[\Override]
    public function storeTagGenerations(array $generations): bool
    {
        foreach ($generations as $tag => $generation) {
            if (!self::isGeneration($generation)) {
                return false;
            }
            $this->metadata[$tag] = strtolower($generation);
        }

        return true;
    }

    private function atomicRecord(string $mapped): ?CacheRecord
    {
        $blob = $this->store[$mapped] ?? null;
        if (!is_string($blob)) {
            return null;
        }

        $record = $this->decodeRecordFromBlob($blob);
        if (!$record instanceof CacheRecord || !$this->recordTagsAreCurrent($record)) {
            unset($this->store[$mapped]);

            return null;
        }

        return $record;
    }

    private function map(string $key): string
    {
        return $this->ns . ':d:' . $key;
    }

    private function recordTagsAreCurrent(CacheRecord $record): bool
    {
        foreach ($record->tags as $tag => $generation) {
            if (($this->metadata[$tag] ?? null) !== $generation) {
                return false;
            }
        }

        return true;
    }
}
