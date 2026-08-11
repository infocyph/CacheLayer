<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cache\Adapter;

use Closure;
use Infocyph\CacheLayer\Cache\CacheInput;
use Infocyph\CacheLayer\Cache\Item\CacheItem;
use Psr\Cache\CacheItemInterface;
use WeakReference;

final class WeakMapCacheAdapter extends AbstractCacheAdapter
{
    private readonly string $ns;

    /** @var array<string, string> */
    private array $scalarStore = [];

    /** @var array<string, int|null> */
    private array $weakExpires = [];

    /** @var array<string, WeakReference<object>> */
    private array $weakRefs = [];

    /** @var array<string, array<string, string>> */
    private array $weakTags = [];

    public function __construct(string $namespace = 'default')
    {
        $this->ns = CacheInput::namespace($namespace);
    }

    public function clear(): bool
    {
        $this->scalarStore = [];
        $this->weakRefs = [];
        $this->weakExpires = [];
        $this->weakTags = [];
        $this->deferred = [];
        $this->resetLocalMetadata();

        return true;
    }

    public function deleteItem(string $key): bool
    {
        $mapped = $this->map($key);
        unset($this->scalarStore[$mapped], $this->weakExpires[$mapped], $this->weakTags[$mapped]);

        unset($this->weakRefs[$mapped]);

        return true;
    }

    /**
     * @param array $keys The keys argument.
     * @phpstan-param list<string> $keys
     */
    public function deleteItems(array $keys): bool
    {
        foreach ($keys as $key) {
            $this->deleteItem((string) $key);
        }

        return true;
    }

    public function getItem(string $key): CacheItem
    {
        $this->pruneCollected();
        $mapped = $this->map($key);

        if (isset($this->weakRefs[$mapped])) {
            $ref = $this->weakRefs[$mapped];
            $obj = $ref->get();
            $exp = $this->weakExpires[$mapped] ?? null;

            if (is_object($obj) && !CachePayloadCodec::isExpired($exp)) {
                return new CacheItem(
                    $this,
                    $key,
                    $obj,
                    true,
                    CachePayloadCodec::toDateTime($exp),
                    $this->weakTags[$mapped] ?? [],
                );
            }

            $this->deleteItem($key);
        }

        if (!isset($this->scalarStore[$mapped])) {
            return new CacheItem($this, $key);
        }

        return $this->genericFromBlobWithInvalidator(
            $key,
            $this->scalarStore[$mapped],
            function () use ($mapped): bool {
                unset($this->scalarStore[$mapped]);

                return true;
            },
        );
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
        $this->pruneCollected();
        $items = [];
        $staleScalar = [];
        foreach ($keys as $key) {
            $mapped = $this->map($key);
            $reference = $this->weakRefs[$mapped] ?? null;
            $object = $reference instanceof WeakReference ? $reference->get() : null;
            $expiresAt = $this->weakExpires[$mapped] ?? null;
            if (is_object($object) && !CachePayloadCodec::isExpired($expiresAt)) {
                $items[$key] = new CacheItem(
                    $this,
                    $key,
                    $object,
                    true,
                    CachePayloadCodec::toDateTime($expiresAt),
                    $this->weakTags[$mapped] ?? [],
                );

                continue;
            }
            $blob = $this->scalarStore[$mapped] ?? null;
            $record = is_string($blob) ? $this->decodeRecordFromBlob($blob) : null;
            $items[$key] = $record === null
                ? $this->genericMiss($key)
                : $this->genericItemFromRecord($key, $record);
            if ($blob !== null && $record === null) {
                $staleScalar[] = $mapped;
            }
        }
        foreach ($staleScalar as $mapped) {
            unset($this->scalarStore[$mapped]);
        }

        return $items;
    }

    public function save(CacheItemInterface $item): bool
    {
        return $this->saveEncoded($item, $this->persistItem(...));
    }

    /** @param array<string, CacheItemInterface> $items */
    public function saveItems(array $items): bool
    {
        if (!$this->supportsItems($items)) {
            return false;
        }

        foreach ($items as $item) {
            $expires = CachePayloadCodec::expirationFromItem($item);
            if ($expires['ttl'] !== null && $expires['ttl'] <= 0) {
                $this->deleteItem($item->getKey());

                continue;
            }

            if (!$this->persistItem($item, $expires)) {
                return false;
            }
        }

        return true;
    }

    private function map(string $key): string
    {
        return $this->ns . ':d:' . $key;
    }

    /** @param array{ttl:int|null, expiresAt:int|null} $expires */
    private function persistItem(CacheItemInterface $item, array $expires): bool
    {
        $mapped = $this->map($item->getKey());
        $value = $item->get();

        if (is_object($value)) {
            if ($value instanceof Closure && !$this->options()->allowClosures) {
                return false;
            }
            if (!$value instanceof Closure && !$this->options()->allowObjects) {
                return false;
            }
            $ref = WeakReference::create($value);
            $this->weakRefs[$mapped] = $ref;
            $this->weakExpires[$mapped] = $expires['expiresAt'];
            $this->weakTags[$mapped] = $item instanceof CacheItem
                ? $item->getTagGenerations()
                : [];
            unset($this->scalarStore[$mapped]);

            return true;
        }

        unset($this->weakRefs[$mapped], $this->weakExpires[$mapped], $this->weakTags[$mapped]);
        $this->scalarStore[$mapped] = $this->encodeItem($item, $expires['expiresAt']);

        return true;
    }

    private function pruneCollected(): void
    {
        foreach ($this->weakRefs as $mapped => $ref) {
            $obj = $ref->get();
            if (!is_object($obj) || CachePayloadCodec::isExpired($this->weakExpires[$mapped] ?? null)) {
                unset($this->weakRefs[$mapped], $this->weakExpires[$mapped], $this->weakTags[$mapped]);
            }
        }
    }
}
