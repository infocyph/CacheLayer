<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cache\Adapter;

use Countable;
use Infocyph\CacheLayer\Cache\Item\GenericCacheItem;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Abstract base class for cache adapter implementations.
 *
 * This class provides a foundation for building PSR-6 and PSR-16 compliant
 * cache adapters. It implements common functionality like deferred item management
 * and provides default implementations for several cache pool interface methods.
 *
 * Adapters extending this class must implement the abstract methods required
 * for their specific storage mechanism while inheriting common cache operations.
 */
abstract class AbstractCacheAdapter implements CacheItemPoolInterface, Countable, InternalCachePoolInterface
{
    /** @var array<string, CacheItemInterface> */
    protected array $deferred = [];

    /**
     * Determines if this adapter supports the given cache item.
     *
     * @param CacheItemInterface $item The cache item to check.
     * @return bool True if the adapter supports this item type.
     */
    abstract protected function supportsItem(CacheItemInterface $item): bool;

    public function commit(): bool
    {
        $ok = true;
        foreach ($this->deferred as $key => $item) {
            $ok = $ok && $this->save($item);
            unset($this->deferred[$key]);
        }

        return $ok;
    }

    public function get(string $key): mixed
    {
        $item = $this->getItem($key);

        return $item->isHit() ? $item->get() : null;
    }

    /**
     * @param list<string> $keys
     * @return iterable<string, CacheItemInterface>
     */
    public function getItems(array $keys = []): iterable
    {
        foreach ($keys as $key) {
            yield $key => $this->getItem($key);
        }
    }

    public function internalPersist(CacheItemInterface $item): bool
    {
        return $this->save($item);
    }

    public function internalQueue(CacheItemInterface $item): bool
    {
        return $this->saveDeferred($item);
    }

    public function saveDeferred(CacheItemInterface $item): bool
    {
        if (!$this->supportsItem($item)) {
            return false;
        }
        $this->deferred[$item->getKey()] = $item;

        return true;
    }

    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        $item = $this->getItem($key);
        $item->set($value)->expiresAfter($ttl);

        return $this->save($item);
    }

    /**
     * @return array{value:mixed,expires:int|null}|null
     */
    protected function decodeRecordFromBase64(string $payload): ?array
    {
        $blob = base64_decode($payload, true);
        if (!is_string($blob)) {
            return null;
        }

        return $this->decodeRecordFromBlob($blob);
    }

    /**
     * @return array{value:mixed,expires:int|null}|null
     */
    protected function decodeRecordFromBlob(string $blob): ?array
    {
        $record = CachePayloadCodec::decode($blob);
        if ($record === null || CachePayloadCodec::isExpired($record['expires'])) {
            return null;
        }

        return $record;
    }

    protected function genericDeleteAndMiss(string $key): GenericCacheItem
    {
        $this->deleteItem($key);

        return $this->genericMiss($key);
    }

    protected function genericFromBase64(string $key, ?string $payload): GenericCacheItem
    {
        return $this->genericFromBase64WithInvalidator(
            $key,
            $payload,
            fn(): bool => $this->deleteItem($key),
        );
    }

    /**
     * @param callable():bool $onInvalid
     */
    protected function genericFromBase64WithInvalidator(string $key, ?string $payload, callable $onInvalid): GenericCacheItem
    {
        return $this->genericFromEncodedWithInvalidator($key, $payload, $onInvalid, $this->decodeRecordFromBase64(...));
    }

    protected function genericFromBlob(string $key, ?string $blob): GenericCacheItem
    {
        return $this->genericFromBlobWithInvalidator(
            $key,
            $blob,
            fn(): bool => $this->deleteItem($key),
        );
    }

    /**
     * @param callable():bool $onInvalid
     */
    protected function genericFromBlobWithInvalidator(string $key, ?string $blob, callable $onInvalid): GenericCacheItem
    {
        return $this->genericFromEncodedWithInvalidator($key, $blob, $onInvalid, $this->decodeRecordFromBlob(...));
    }

    /**
     * @param array{value:mixed,expires:int|null} $record
     */
    protected function genericItemFromRecord(string $key, array $record): GenericCacheItem
    {
        $item = new GenericCacheItem($this, $key);
        $item->set($record['value']);
        if ($record['expires'] !== null) {
            $item->expiresAt(CachePayloadCodec::toDateTime($record['expires']));
        }

        return $item;
    }

    protected function genericMiss(string $key): GenericCacheItem
    {
        return new GenericCacheItem($this, $key);
    }

    /**
     * @template T of CacheItemInterface
     *
     * @param list<string> $keys
     * @param callable(string):T $fetcher
     * @return array<string, T>
     */
    protected function multiFetchItems(array $keys, callable $fetcher): array
    {
        $items = [];
        foreach ($keys as $key) {
            $items[$key] = $fetcher($key);
        }

        return $items;
    }

    /**
     * @param callable(CacheItemInterface,array{ttl:int|null,expiresAt:int|null}):bool $writer
     */
    protected function saveEncoded(CacheItemInterface $item, callable $writer): bool
    {
        if (!$this->supportsItem($item)) {
            return false;
        }

        $expires = CachePayloadCodec::expirationFromItem($item);
        if ($expires['ttl'] === 0) {
            return $this->deleteItem($item->getKey());
        }

        return $writer($item, $expires);
    }

    /**
     * @param callable(string):(array{value:mixed,expires:int|null}|null) $decoder
     * @param callable():bool $onInvalid
     */
    private function genericFromEncodedWithInvalidator(
        string $key,
        ?string $encoded,
        callable $onInvalid,
        callable $decoder,
    ): GenericCacheItem {
        if (!is_string($encoded)) {
            return $this->genericMiss($key);
        }

        $record = $decoder($encoded);
        if ($record === null) {
            $onInvalid();

            return $this->genericMiss($key);
        }

        return $this->genericItemFromRecord($key, $record);
    }
}
