<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cache\Adapter;

use Infocyph\CacheLayer\Cache\CacheOptions;
use Infocyph\CacheLayer\Cache\CacheRecord;
use Infocyph\CacheLayer\Cache\Item\CacheItem;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;

abstract class AbstractCacheAdapter implements CacheItemPoolInterface, InternalCachePoolInterface
{
    /** @var array<string, CacheItemInterface> */
    protected array $deferred = [];

    private ?CachePayloadCodec $codec = null;

    /** @var array<string, int> */
    private array $localMetadata = [];

    private ?CacheOptions $options = null;

    /**
     * @param list<string> $keys
     * @return array<string, CacheItem>
     */
    abstract public function multiFetch(array $keys): array;

    /** @param array<string, CacheItemInterface> $items */
    abstract public function saveItems(array $items): bool;

    public function commit(): bool
    {
        if ($this->deferred === []) {
            return true;
        }

        $deferred = $this->deferred;
        $saved = $this->saveItems($deferred);
        if ($saved) {
            $this->deferred = [];
        }

        return $saved;
    }

    /** @internal */
    public function configureOptions(CacheOptions $options): void
    {
        if ($this->codec !== null) {
            if ($this->options == $options) {
                return;
            }

            throw new \LogicException('Cache options cannot change after the adapter starts processing records.');
        }

        $this->options = $options;
    }

    public function createItem(string $key): CacheItemInterface
    {
        return $this->genericMiss($key);
    }

    public function get(string $key): mixed
    {
        $item = $this->getItem($key);

        return $item->isHit() ? $item->get() : null;
    }

    /**
     * @param list<string> $keys
     * @return array<string, CacheItem>
     */
    public function getItems(array $keys = []): array
    {
        return $this->multiFetch($keys);
    }

    /** @param list<string> $tags */
    public function getTagVersions(array $tags): array
    {
        $versions = [];
        foreach ($tags as $tag) {
            $versions[$tag] = $this->localMetadata[$tag] ?? 0;
        }

        return $versions;
    }

    /** @param list<string> $tags */
    public function incrementTagVersions(array $tags): bool
    {
        foreach ($tags as $tag) {
            $this->localMetadata[$tag] = ($this->localMetadata[$tag] ?? 0) + 1;
        }

        return true;
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

    protected function decodeRecordFromBase64(string $payload): ?CacheRecord
    {
        $blob = base64_decode($payload, true);

        return is_string($blob) ? $this->decodeRecordFromBlob($blob) : null;
    }

    protected function decodeRecordFromBlob(string $blob): ?CacheRecord
    {
        $record = $this->payloadCodec()->decode($blob);

        return $record !== null && !CachePayloadCodec::isExpired($record->expiresAt)
            ? $record
            : null;
    }

    protected function encodeItem(
        CacheItemInterface $item,
        ?int $expiresAt,
        ?int $namespaceEpoch = null,
    ): string {
        $tags = $item instanceof CacheItem ? $item->getTagVersions() : [];

        return $this->payloadCodec()->encode($item->get(), $expiresAt, $tags, $namespaceEpoch);
    }

    protected function genericDeleteAndMiss(string $key): CacheItem
    {
        $this->deleteItem($key);

        return $this->genericMiss($key);
    }

    protected function genericFromBase64(string $key, ?string $payload): CacheItem
    {
        return $this->genericFromBase64WithInvalidator(
            $key,
            $payload,
            fn(): bool => $this->deleteItem($key),
        );
    }

    /** @param callable(): bool $onInvalid */
    protected function genericFromBase64WithInvalidator(
        string $key,
        ?string $payload,
        callable $onInvalid,
    ): CacheItem {
        return $this->genericFromEncodedWithInvalidator(
            $key,
            $payload,
            $onInvalid,
            $this->decodeRecordFromBase64(...),
        );
    }

    protected function genericFromBlob(string $key, ?string $blob): CacheItem
    {
        return $this->genericFromBlobWithInvalidator(
            $key,
            $blob,
            fn(): bool => $this->deleteItem($key),
        );
    }

    /** @param callable(): bool $onInvalid */
    protected function genericFromBlobWithInvalidator(
        string $key,
        ?string $blob,
        callable $onInvalid,
    ): CacheItem {
        return $this->genericFromEncodedWithInvalidator(
            $key,
            $blob,
            $onInvalid,
            $this->decodeRecordFromBlob(...),
        );
    }

    protected function genericItemFromRecord(string $key, CacheRecord $record): CacheItem
    {
        return new CacheItem(
            $this,
            $key,
            $record->value,
            true,
            CachePayloadCodec::toDateTime($record->expiresAt),
            $record->tags,
        );
    }

    protected function genericMiss(string $key): CacheItem
    {
        return new CacheItem($this, $key);
    }

    /**
     * @param list<string> $keys
     * @param callable(string): CacheItem $fetcher
     * @return array<string, CacheItem>
     */
    protected function multiFetchItems(array $keys, callable $fetcher): array
    {
        $items = [];
        foreach ($keys as $key) {
            $items[$key] = $fetcher($key);
        }

        return $items;
    }

    protected function resetLocalMetadata(): void
    {
        $this->localMetadata = [];
    }

    /**
     * @param callable(CacheItemInterface, array{ttl:int|null, expiresAt:int|null}): bool $writer
     */
    protected function saveEncoded(CacheItemInterface $item, callable $writer): bool
    {
        if (!$this->supportsItem($item)) {
            return false;
        }

        $expiration = CachePayloadCodec::expirationFromItem($item);
        if ($expiration['ttl'] !== null && $expiration['ttl'] <= 0) {
            return $this->deleteItem($item->getKey());
        }

        return $writer($item, $expiration);
    }

    protected function supportsItem(CacheItemInterface $item): bool
    {
        return $item instanceof CacheItem && $item->belongsTo($this);
    }

    /** @param array<string, CacheItemInterface> $items */
    protected function supportsItems(array $items): bool
    {
        foreach ($items as $item) {
            if (!$this->supportsItem($item)) {
                return false;
            }
        }

        return true;
    }

    private function genericFromEncodedWithInvalidator(
        string $key,
        ?string $encoded,
        callable $onInvalid,
        callable $decoder,
    ): CacheItem {
        if ($encoded === null) {
            return $this->genericMiss($key);
        }

        $record = $decoder($encoded);
        if (!$record instanceof CacheRecord) {
            $onInvalid();

            return $this->genericMiss($key);
        }

        return $this->genericItemFromRecord($key, $record);
    }

    private function payloadCodec(): CachePayloadCodec
    {
        $this->options ??= new CacheOptions();

        return $this->codec ??= new CachePayloadCodec($this->options);
    }
}
