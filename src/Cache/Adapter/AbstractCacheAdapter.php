<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cache\Adapter;

use Infocyph\CacheLayer\Cache\CacheInput;
use Infocyph\CacheLayer\Cache\CacheOptions;
use Infocyph\CacheLayer\Cache\CacheRecord;
use Infocyph\CacheLayer\Cache\Item\CacheItem;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Throwable;

abstract class AbstractCacheAdapter implements CacheItemPoolInterface, InternalCachePoolInterface
{
    /** @var array<string, CacheItemInterface> */
    protected array $deferred = [];

    private ?CachePayloadCodec $codec = null;

    private bool $committing = false;

    /** @var array<string, string> */
    private array $localMetadata = [];

    private ?CacheOptions $options = null;

    private ?string $storageIdentity = null;

    public function __destruct()
    {
        if ($this->deferred === []) {
            return;
        }

        try {
            $this->commit();
        } catch (Throwable) {
        }
    }

    /**
     * @param list<string> $keys
     * @return array<string, CacheItem>
     */
    abstract public function multiFetch(array $keys): array;

    /** @param array<string, CacheItemInterface> $items */
    abstract public function saveItems(array $items): bool;

    /** @internal */
    public function assertOptionsCompatible(CacheOptions $options): void
    {
        if ($this->options !== null && $this->options != $options) {
            throw new \LogicException('Cache options cannot change after the adapter is bound to a facade.');
        }
    }

    /** @internal */
    public function assertStorageIdentityCompatible(string $storageIdentity): void
    {
        if ($this->storageIdentity !== null && $this->storageIdentity !== $storageIdentity) {
            throw new \LogicException('Cache storage identity cannot change after the adapter is bound to a facade.');
        }
    }

    public function commit(): bool
    {
        if ($this->deferred === []) {
            return true;
        }

        $deferred = $this->deferred;
        $this->committing = true;

        try {
            $saved = $this->saveItems($deferred);
        } finally {
            $this->committing = false;
        }

        if ($saved) {
            $this->deferred = [];
        }

        return $saved;
    }

    /** @internal */
    public function configureOptions(CacheOptions $options): void
    {
        $this->assertOptionsCompatible($options);
        $this->options ??= $options;
    }

    /** @internal */
    public function configureStorageIdentity(string $storageIdentity): void
    {
        $this->assertStorageIdentityCompatible($storageIdentity);
        $this->storageIdentity ??= $storageIdentity;
    }

    public function createItem(string $key): CacheItemInterface
    {
        CacheInput::key($key);

        return new CacheItem($this, $key);
    }

    /**
     * @param list<string> $keys
     * @return array<string, CacheItem>
     */
    public function getItems(array $keys = []): array
    {
        $keys = CacheInput::keys($keys);
        $items = $this->multiFetch($keys);

        foreach ($keys as $key) {
            $pending = $this->deferredRead($key);
            if ($pending !== null) {
                $items[$key] = $pending;
            } elseif (!isset($items[$key])) {
                $items[$key] = new CacheItem($this, $key);
            }
        }

        return $items;
    }

    /** @param list<string> $tags */
    public function getTagGenerations(array $tags): array
    {
        $generations = [];
        foreach ($tags as $tag) {
            $generations[$tag] = $this->localMetadata[$tag] ??= self::newGeneration();
        }

        return $generations;
    }

    public function internalPersist(CacheItemInterface $item): bool
    {
        return $this->save($item);
    }

    public function internalQueue(CacheItemInterface $item): bool
    {
        return $this->saveDeferred($item);
    }

    /** @param list<string> $tags */
    public function rotateTagGenerations(array $tags): bool
    {
        foreach ($tags as $tag) {
            $this->localMetadata[$tag] = self::newGeneration();
        }

        return true;
    }

    public function saveDeferred(CacheItemInterface $item): bool
    {
        if (!$this->supportsItem($item)) {
            return false;
        }

        $snapshot = $this->deferredSnapshot($item);
        $this->deferred[$this->deferredKey($item->getKey())] = $snapshot;

        return true;
    }

    protected static function isGeneration(mixed $value): bool
    {
        return is_string($value) && strlen($value) === 32 && ctype_xdigit($value);
    }

    protected static function newGeneration(): string
    {
        return bin2hex(random_bytes(16));
    }

    protected static function normalizeGeneration(mixed $value): ?string
    {
        if (!is_string($value) || strlen($value) !== 32 || !ctype_xdigit($value)) {
            return null;
        }

        return strtolower($value);
    }

    protected function decodeRecordFromBase64(string $payload, ?string $key = null): ?CacheRecord
    {
        $blob = base64_decode($payload, true);

        return is_string($blob) ? $this->decodeRecordFromBlob($blob, $key) : null;
    }

    protected function decodeRecordFromBlob(string $blob, ?string $key = null): ?CacheRecord
    {
        $record = $this->payloadCodec()->decode($blob, $this->storageIdentity, $key);

        return $record !== null && !CachePayloadCodec::isExpired($record->expiresAt)
            ? $record
            : null;
    }

    protected function deferredRead(string $key): ?CacheItem
    {
        $pending = $this->deferred[$this->deferredKey($key)] ?? null;
        if (!$pending instanceof CacheItem) {
            return null;
        }
        if (!$pending->isHit()) {
            return new CacheItem($this, $key);
        }

        return clone $pending;
    }

    protected function discardDeferredKey(string $key): void
    {
        CacheInput::key($key);
        if ($this->committing) {
            return;
        }

        unset($this->deferred[$this->deferredKey($key)]);
    }

    /** @param list<string> $keys */
    protected function discardDeferredKeys(array $keys): void
    {
        $keys = CacheInput::keys($keys);
        if ($this->committing) {
            return;
        }

        foreach ($keys as $key) {
            unset($this->deferred[$this->deferredKey($key)]);
        }
    }

    protected function encodeItem(
        CacheItemInterface $item,
        ?int $expiresAt,
        ?string $namespaceGeneration = null,
    ): string {
        $tags = $item instanceof CacheItem ? $item->getTagGenerations() : [];

        return $this->payloadCodec()->encode(
            $item->get(),
            $expiresAt,
            $tags,
            $namespaceGeneration,
            $this->storageIdentity,
            $item->getKey(),
        );
    }

    protected function genericDeleteAndMiss(string $key): CacheItem
    {
        $this->deleteItem($key);

        return $this->genericMiss($key);
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
            fn(string $encoded): ?CacheRecord => $this->decodeRecordFromBase64($encoded, $key),
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
            fn(string $encoded): ?CacheRecord => $this->decodeRecordFromBlob($encoded, $key),
        );
    }

    protected function genericItemFromRecord(string $key, CacheRecord $record): CacheItem
    {
        CacheInput::key($key);
        $pending = $this->deferredRead($key);
        if ($pending !== null) {
            return $pending;
        }

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
        CacheInput::key($key);

        return $this->deferredRead($key) ?? new CacheItem($this, $key);
    }

    protected function options(): CacheOptions
    {
        return $this->options ??= new CacheOptions();
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
        if (!$this->ownsItem($item)) {
            return false;
        }
        if (!$this->committing) {
            $this->discardDeferredKey($item->getKey());
        }

        return true;
    }

    /** @param array<string, CacheItemInterface> $items */
    protected function supportsItems(array $items): bool
    {
        foreach ($items as $item) {
            if (!$this->ownsItem($item)) {
                return false;
            }
        }
        if (!$this->committing) {
            foreach ($items as $item) {
                $this->discardDeferredKey($item->getKey());
            }
        }

        return true;
    }

    private function deferredKey(string $key): string
    {
        return "key:\0" . $key;
    }

    private function deferredSnapshot(CacheItemInterface $item): CacheItem
    {
        $ttl = $item instanceof CacheItem ? $item->ttlSeconds() : null;
        $tags = $item instanceof CacheItem ? $item->getTagGenerations() : [];

        return (new CacheItem($this, $item->getKey(), $item->get(), true))
            ->expiresAfter($ttl)
            ->setTagGenerations($tags);
    }

    private function genericFromEncodedWithInvalidator(
        string $key,
        ?string $encoded,
        callable $onInvalid,
        callable $decoder,
    ): CacheItem {
        $pending = $this->deferredRead($key);
        if ($pending !== null) {
            return $pending;
        }
        if ($encoded === null) {
            return new CacheItem($this, $key);
        }

        $record = $decoder($encoded);
        if (!$record instanceof CacheRecord) {
            $onInvalid();

            return new CacheItem($this, $key);
        }

        return $this->genericItemFromRecord($key, $record);
    }

    private function ownsItem(CacheItemInterface $item): bool
    {
        return $item instanceof CacheItem && $item->belongsTo($this);
    }

    private function payloadCodec(): CachePayloadCodec
    {
        $this->options ??= new CacheOptions();

        return $this->codec ??= new CachePayloadCodec($this->options);
    }
}
