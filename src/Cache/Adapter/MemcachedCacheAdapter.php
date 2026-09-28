<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cache\Adapter;

use Infocyph\CacheLayer\Cache\CacheInput;
use Infocyph\CacheLayer\Cache\CacheRecord;
use Infocyph\CacheLayer\Cache\Item\CacheItem;
use Infocyph\CacheLayer\Support\MemcachedValueGuard;
use Psr\Cache\CacheItemInterface;
use RuntimeException;

final class MemcachedCacheAdapter extends AbstractCacheAdapter implements AtomicCachePoolInterface
{
    private const string ATOMIC_TOMBSTONE = "\0cachelayer:atomic:tombstone:v1\0";

    private readonly \Memcached $client;

    private readonly string $namespace;

    /**
     * @param list<array{0:string, 1:int, 2:int}> $servers
     */
    public function __construct(
        string $namespace = 'default',
        array $servers = [['127.0.0.1', 11211, 0]],
        ?\Memcached $client = null,
    ) {
        if (!class_exists(\Memcached::class)) {
            throw new RuntimeException('Memcached extension not loaded');
        }
        $this->namespace = CacheInput::namespace($namespace);
        $this->client = $client ?? new \Memcached();
        if ($client === null) {
            $this->client->addServers($servers);
        }
    }

    public function atomicCompareAndSet(
        string $key,
        mixed $expected,
        CacheItemInterface $replacement,
    ): bool {
        if (!$this->supportsItem($replacement)) {
            return false;
        }

        $expiration = CachePayloadCodec::expirationFromItem($replacement);
        $ttl = $expiration['ttl'];
        if ($ttl !== null && $ttl <= 0) {
            return false;
        }

        $mapped = $this->mapData($key);
        $extended = $this->extendedGet($mapped);
        if ($extended === null) {
            return false;
        }

        $blob = $extended['value'];
        if ($blob === self::ATOMIC_TOMBSTONE) {
            return false;
        }

        $record = $this->decodeRecordFromBlob($blob, $key);
        if (!$record instanceof CacheRecord
            || $record->namespaceGeneration !== $this->namespaceGeneration()
            || $record->tags !== []
            || $record->value !== $expected) {
            return false;
        }

        $replacementBlob = $this->encodeItem(
            $replacement,
            $expiration['expiresAt'],
            $this->namespaceGeneration(),
        );

        return $this->client->cas(
            $extended['cas'],
            $mapped,
            $replacementBlob,
            MemcachedExpiration::fromRelative($ttl),
        );
    }

    public function atomicGetAndDelete(string $key): CacheItemInterface
    {
        $mapped = $this->mapData($key);
        $extended = $this->extendedGet($mapped);
        if ($extended === null || $extended['value'] === self::ATOMIC_TOMBSTONE) {
            return $this->genericMiss($key);
        }

        $record = $this->decodeRecordFromBlob($extended['value'], $key);
        if (!$record instanceof CacheRecord
            || $record->namespaceGeneration !== $this->namespaceGeneration()
            || !$this->recordTagsAreCurrent($record)) {
            return $this->genericMiss($key);
        }

        if (!$this->client->cas($extended['cas'], $mapped, self::ATOMIC_TOMBSTONE, 1)) {
            return $this->genericMiss($key);
        }

        return $this->genericItemFromRecord($key, $record);
    }

    public function atomicSetIfAbsent(CacheItemInterface $item): bool
    {
        if (!$this->supportsItem($item)) {
            return false;
        }

        $expiration = CachePayloadCodec::expirationFromItem($item);
        $ttl = $expiration['ttl'];
        if ($ttl !== null && $ttl <= 0) {
            return false;
        }

        $mapped = $this->mapData($item->getKey());
        $blob = $this->encodeItem(
            $item,
            $expiration['expiresAt'],
            $this->namespaceGeneration(),
        );

        if ($this->client->add($mapped, $blob, MemcachedExpiration::fromRelative($ttl))) {
            return true;
        }

        $extended = $this->extendedGet($mapped);
        if ($extended === null) {
            return $this->client->add($mapped, $blob, MemcachedExpiration::fromRelative($ttl));
        }

        $current = $extended['value'];
        $record = $current === self::ATOMIC_TOMBSTONE
            ? null
            : $this->decodeRecordFromBlob($current, $item->getKey());
        if ($record instanceof CacheRecord
            && $record->namespaceGeneration === $this->namespaceGeneration()
            && $this->recordTagsAreCurrent($record)) {
            return false;
        }

        return $this->client->cas($extended['cas'], $mapped, $blob, MemcachedExpiration::fromRelative($ttl));
    }

    public function clear(): bool
    {
        $cleared = $this->client->set($this->generationKey(), self::newGeneration());
        $this->deferred = [];

        return $cleared;
    }

    public function deleteItem(string $key): bool
    {
        $this->discardDeferredKey($key);
        $this->client->delete($this->mapData($key));

        return $this->deleteResultSucceeded();
    }

    /** @param list<string> $keys */
    public function deleteItems(array $keys): bool
    {
        $this->discardDeferredKeys($keys);
        if ($keys === []) {
            return true;
        }

        $this->client->deleteMulti(array_map($this->mapData(...), $keys));

        return $this->deleteResultSucceeded();
    }

    public function getClient(): \Memcached
    {
        return $this->client;
    }

    public function getItem(string $key): CacheItem
    {
        $mapped = $this->mapData($key);
        $stored = $this->client->getMulti([$this->generationKey(), $mapped]);
        $stored = is_array($stored) ? $stored : [];
        $generation = $this->namespaceGeneration($stored[$this->generationKey()] ?? null);
        $blob = $stored[$mapped] ?? null;
        if ($blob === self::ATOMIC_TOMBSTONE) {
            return $this->genericMiss($key);
        }
        $record = is_string($blob) ? $this->decodeRecordFromBlob($blob, $key) : null;
        if ($record !== null && $record->namespaceGeneration === $generation) {
            return $this->genericItemFromRecord($key, $record);
        }
        if (is_string($blob)) {
            MemcachedValueGuard::replaceIfUnchanged(
                $this->client,
                $mapped,
                $blob,
                self::ATOMIC_TOMBSTONE,
                1,
            );
        }

        return $this->genericMiss($key);
    }

    /**
     * @param list<string> $tags
     * @return array<string, string>
     */
    #[\Override]
    public function getTagGenerations(array $tags): array
    {
        if ($tags === []) {
            return [];
        }
        $stored = $this->client->getMulti(array_map($this->mapTag(...), $tags));
        $stored = is_array($stored) ? $stored : [];
        $generations = [];
        foreach ($tags as $tag) {
            $key = $this->mapTag($tag);
            $generations[$tag] = $this->tagGeneration($key, $stored[$key] ?? null);
        }

        return $generations;
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
        if ($keys === []) {
            return [];
        }

        $physical = [$this->generationKey()];
        foreach ($keys as $key) {
            $physical[] = $this->mapData($key);
        }
        $stored = $this->client->getMulti($physical, \Memcached::GET_PRESERVE_ORDER);
        $stored = is_array($stored) ? $stored : [];
        $generation = $this->namespaceGeneration($stored[$this->generationKey()] ?? null);
        $items = [];
        $stale = [];
        foreach ($keys as $key) {
            $mapped = $this->mapData($key);
            $blob = $stored[$mapped] ?? null;
            if ($blob === self::ATOMIC_TOMBSTONE) {
                $items[$key] = $this->genericMiss($key);

                continue;
            }
            $record = is_string($blob) ? $this->decodeRecordFromBlob($blob, $key) : null;
            if ($record === null || $record->namespaceGeneration !== $generation) {
                $items[$key] = $this->genericMiss($key);
                if (is_string($blob)) {
                    $stale[] = [$mapped, $blob];
                }

                continue;
            }
            $items[$key] = $this->genericItemFromRecord($key, $record);
        }
        foreach ($stale as [$mapped, $observed]) {
            MemcachedValueGuard::replaceIfUnchanged(
                $this->client,
                $mapped,
                $observed,
                self::ATOMIC_TOMBSTONE,
                1,
            );
        }

        return $items;
    }

    /** @param list<string> $tags */
    #[\Override]
    public function rotateTagGenerations(array $tags): bool
    {
        $generations = [];
        foreach ($tags as $tag) {
            $generations[$this->mapTag($tag)] = self::newGeneration();
        }

        return $generations === [] || $this->client->setMulti($generations);
    }

    public function save(CacheItemInterface $item): bool
    {
        if (!$this->supportsItem($item)) {
            return false;
        }
        $expiration = CachePayloadCodec::expirationFromItem($item);
        if ($expiration['ttl'] !== null && $expiration['ttl'] <= 0) {
            return $this->deleteItem($item->getKey());
        }

        return $this->client->set(
            $this->mapData($item->getKey()),
            $this->encodeItem($item, $expiration['expiresAt'], $this->namespaceGeneration()),
            MemcachedExpiration::fromRelative($expiration['ttl']),
        );
    }

    /** @param array<string, CacheItemInterface> $items */
    public function saveItems(array $items): bool
    {
        $generation = $this->namespaceGeneration();
        $groups = [];
        $expired = [];
        foreach ($items as $item) {
            if (!$this->supportsItem($item)) {
                return false;
            }
            $expiration = CachePayloadCodec::expirationFromItem($item);
            if ($expiration['ttl'] !== null && $expiration['ttl'] <= 0) {
                $expired[] = $item->getKey();

                continue;
            }
            $memcachedExpiration = MemcachedExpiration::fromRelative($expiration['ttl']);
            $groups[$memcachedExpiration][$this->mapData($item->getKey())] = $this->encodeItem(
                $item,
                $expiration['expiresAt'],
                $generation,
            );
        }

        if (!$this->deleteItems($expired)) {
            return false;
        }
        foreach ($groups as $memcachedExpiration => $records) {
            if (!$this->client->setMulti($records, $memcachedExpiration)) {
                return false;
            }
        }

        return true;
    }

    private function deleteResultSucceeded(): bool
    {
        return in_array(
            $this->client->getResultCode(),
            [\Memcached::RES_SUCCESS, \Memcached::RES_NOTFOUND],
            true,
        );
    }

    /** @return array{value:string, cas:float}|null */
    private function extendedGet(string $key): ?array
    {
        $value = $this->client->get($key, null, \Memcached::GET_EXTENDED);
        if (!is_array($value) || !is_string($value['value'] ?? null)) {
            return null;
        }
        $cas = $value['cas'] ?? null;
        if (!is_int($cas) && !is_float($cas)) {
            return null;
        }

        return ['value' => $value['value'], 'cas' => (float) $cas];
    }

    private function generationKey(): string
    {
        return $this->namespace . ':m:generation';
    }

    private function initializeGeneration(string $key, mixed $observed, string $failureMessage): string
    {
        $generation = self::normalizeGeneration($observed);
        if ($generation !== null) {
            return $generation;
        }

        $candidate = self::newGeneration();
        $current = is_string($observed)
            ? MemcachedValueGuard::replaceIfUnchanged($this->client, $key, $observed, $candidate)
            : ($this->client->add($key, $candidate) ? $candidate : $this->client->get($key));
        $generation = self::normalizeGeneration($current);
        if ($generation === null) {
            throw new RuntimeException($failureMessage);
        }

        return $generation;
    }

    private function mapData(string $key): string
    {
        return $this->namespace . ':d:' . $key;
    }

    private function mapTag(string $tag): string
    {
        return $this->namespace . ':m:tag:' . $tag;
    }

    private function namespaceGeneration(mixed $value = null): string
    {
        $value ??= $this->client->get($this->generationKey());

        return $this->initializeGeneration(
            $this->generationKey(),
            $value,
            'Unable to initialize Memcached namespace generation.',
        );
    }

    private function recordTagsAreCurrent(CacheRecord $record): bool
    {
        if ($record->tags === []) {
            return true;
        }

        $current = $this->getTagGenerations(array_map(static fn(int|string $tag): string => (string) $tag, array_keys($record->tags)));
        foreach ($record->tags as $tag => $generation) {
            $tag = (string) $tag;
            if (($current[$tag] ?? null) !== $generation) {
                return false;
            }
        }

        return true;
    }

    private function tagGeneration(string $key, mixed $value): string
    {
        return $this->initializeGeneration(
            $key,
            $value,
            'Unable to initialize Memcached tag generation.',
        );
    }
}
