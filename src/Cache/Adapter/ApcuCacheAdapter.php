<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cache\Adapter;

use Infocyph\CacheLayer\Cache\CacheInput;
use Infocyph\CacheLayer\Cache\Item\CacheItem;
use Infocyph\CacheLayer\Exceptions\CacheInvalidArgumentException;
use Psr\Cache\CacheItemInterface;
use RuntimeException;

/**
 * APCu-based cache adapter implementation.
 *
 * This adapter uses the APCu PHP extension to provide high-performance
 * in-memory caching. It's suitable for production environments where
 * shared memory caching is available and provides fast access to cached data.
 *
 * This This adapter requires the APCu extension to be installed and enabled.
     * @param string $namespace A namespace prefix to avoid key collisions.
 */
class ApcuCacheAdapter extends AbstractCacheAdapter implements ConditionalAtomicCachePoolInterface, TagGenerationCacheInterface
{
    use ApcuAtomicOperations;

    private readonly string $ns;

    /**
     * Creates a new APCu cache adapter.
     *
     *
     * @throws RuntimeException If the APCu extension is not enabled.
     * @param string $namespace A namespace prefix to avoid key collisions.
     * @throws CacheInvalidArgumentException
     */
    public function __construct(string $namespace = 'default', private readonly bool $atomicMode = false)
    {
        if (!extension_loaded('apcu') || !apcu_enabled()) {
            throw new RuntimeException('APCu extension is not enabled');
        }
        $this->ns = CacheInput::namespace($namespace);
    }

    /** @throws RuntimeException */
    #[\Override]
    public function clear(): bool
    {
        return $this->atomicMode
            ? $this->withAtomicLock(fn(): bool => $this->clearUnlocked())
            : $this->clearUnlocked();
    }

    /** @throws RuntimeException */
    #[\Override]
    public function deleteItem(string $key): bool
    {
        return $this->atomicMode
            ? $this->withAtomicLock(fn(): bool => $this->deleteItemUnlocked($key))
            : $this->deleteItemUnlocked($key);
    }

    /**
     * @param array $keys The keys argument.
     * @phpstan-param array<array-key, string> $keys
     * @throws RuntimeException
     */
    #[\Override]
    public function deleteItems(array $keys): bool
    {
        return $this->atomicMode
            ? $this->withAtomicLock(fn(): bool => $this->deleteItemsUnlocked($keys))
            : $this->deleteItemsUnlocked($keys);
    }

    #[\Override]
    public function getItem(string $key): CacheItem
    {
        $apcuKey = $this->map($key);
        $success = false;
        $raw = apcu_fetch($apcuKey, $success);

        if ($success && is_string($raw)) {
            $item = $this->hitItemFromBlob($key, $raw);
            if ($item instanceof CacheItem) {
                return $item;
            }

        }

        return $this->genericMiss($key);
    }

    /**
     * @param list<string> $tags
     * @return array<int|string, string>
     * @throws RuntimeException
     */
    #[\Override]
    public function getTagGenerations(array $tags): array
    {
        if ($tags === []) {
            return [];
        }
        $raw = apcu_fetch(array_map($this->mapTag(...), $tags));
        $generations = [];
        foreach ($tags as $tag) {
            $key = $this->mapTag($tag);
            $generation = self::normalizeGeneration(is_array($raw) ? ($raw[$key] ?? null) : null);
            if ($generation === null) {
                $candidate = self::newGeneration();
                $generation = self::normalizeGeneration(apcu_add($key, $candidate) ? $candidate : apcu_fetch($key));
                if ($generation === null) {
                    throw new RuntimeException('Unable to initialize APCu tag generation.');
                }
            }
            $generations[$tag] = $generation;
        }

        return $generations;
    }

    #[\Override]
    public function hasItem(string $key): bool
    {
        return $this->getItem($key)->isHit();
    }

    /**
     * @param array $keys The keys argument.
     * @phpstan-param list<string> $keys
     * @phpstan-return array<string, CacheItem>
     * @throws CacheInvalidArgumentException
     */
    #[\Override]
    public function multiFetch(array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        $prefixed = array_map($this->map(...), $keys);
        $raw = apcu_fetch($prefixed);
        if (!is_array($raw)) {
            $raw = [];
        }

        $items = [];
        foreach ($keys as $k) {
            if ($this->appendFetchedHit($items, $k, $raw)) {
                continue;
            }

            $items[$k] = $this->genericMiss($k);
        }

        return $items;
    }

    /**
     * @param list<string> $tags
     * @return array<int|string, string>
     */
    #[\Override]
    public function readTagGenerations(array $tags): array
    {
        $raw = apcu_fetch(array_map($this->mapTag(...), $tags));
        $generations = [];
        foreach ($tags as $tag) {
            $generation = self::normalizeGeneration(
                is_array($raw) ? ($raw[$this->mapTag($tag)] ?? null) : null,
            );
            if ($generation !== null) {
                $generations[$tag] = $generation;
            }
        }

        return $generations;
    }

    /**
     * @param list<string> $tags
     * @throws RuntimeException
     */
    #[\Override]
    public function rotateTagGenerations(array $tags): bool
    {
        return $this->atomicMode
            ? $this->withAtomicLock(fn(): bool => $this->rotateTagGenerationsUnlocked($tags))
            : $this->rotateTagGenerationsUnlocked($tags);
    }

    /**
     * @throws CacheInvalidArgumentException
     * @throws \InvalidArgumentException
     * @throws RuntimeException
     */
    #[\Override]
    public function save(CacheItemInterface $item): bool
    {
        return $this->atomicMode
            ? $this->withAtomicLock(fn(): bool => $this->saveUnlocked($item))
            : $this->saveUnlocked($item);
    }

    /**
     * @param array<string, CacheItemInterface> $items
     * @throws CacheInvalidArgumentException
     * @throws \InvalidArgumentException
     * @throws RuntimeException
     */
    #[\Override]
    public function saveItems(array $items): bool
    {
        return $this->atomicMode
            ? $this->withAtomicLock(fn(): bool => $this->saveItemsUnlocked($items))
            : $this->saveItemsUnlocked($items);
    }

    /**
     * @param array<int|string, string> $generations
     * @throws RuntimeException
     */
    #[\Override]
    public function storeTagGenerations(array $generations): bool
    {
        return $this->atomicMode
            ? $this->withAtomicLock(fn(): bool => $this->storeTagGenerationsUnlocked($generations))
            : $this->storeTagGenerationsUnlocked($generations);
    }

    /**
     * @param array $items The items argument.
     * @param string $key The key argument.
     * @param array $raw The raw argument.
     * @phpstan-param array<string, CacheItem> $items
     * @phpstan-param array<mixed> $raw
     * @throws CacheInvalidArgumentException
     */
    private function appendFetchedHit(array &$items, string $key, array $raw): bool
    {
        $mapped = $this->map($key);
        if (!isset($raw[$mapped]) || !is_string($raw[$mapped])) {
            return false;
        }

        $item = $this->hitItemFromBlob($key, $raw[$mapped]);
        if ($item instanceof CacheItem) {
            $items[$key] = $item;

            return true;
        }

        return false;
    }

    private function clearUnlocked(): bool
    {
        foreach ($this->listKeys() as $apcuKey) {
            apcu_delete($apcuKey);
        }
        $this->deferred = [];

        return true;
    }

    /**
     * @param array<array-key, string> $keys
     * @throws CacheInvalidArgumentException
     */
    private function deleteItemsUnlocked(array $keys): bool
    {
        $this->discardDeferredKeys($keys);
        $keys = array_values($keys);
        if ($keys === []) {
            return true;
        }

        return apcu_delete(array_map($this->map(...), $keys)) === [];
    }

    /** @throws CacheInvalidArgumentException */
    private function deleteItemUnlocked(string $key): bool
    {
        $this->discardDeferredKey($key);
        $mapped = $this->map($key);
        if (!apcu_exists($mapped)) {
            return true;
        }

        return apcu_delete($mapped);
    }

    /**
     * @throws CacheInvalidArgumentException
     */
    private function hitItemFromBlob(string $key, string $blob): ?CacheItem
    {
        $record = $this->decodeRecordFromBlob($blob, $key);
        if ($record === null) {
            return null;
        }

        return $this->genericItemFromRecord($key, $record);
    }

    /**
     * @phpstan-return list<string>
     */
    private function listKeys(string $keyspace = ''): array
    {
        $iter = new \APCUIterator(
            '/^' . preg_quote($this->ns . ':' . $keyspace, '/') . '/',
            APC_ITER_KEY,
        );
        $out = [];
        for ($iter->rewind(); $iter->valid(); $iter->next()) {
            $out[] = $iter->key();
        }

        return $out;
    }

    private function map(string $key): string
    {
        return $this->ns . ':d:' . $key;
    }

    private function mapTag(string $tag): string
    {
        return $this->ns . ':m:tag:' . $tag;
    }

    /** @param list<string> $tags */
    private function rotateTagGenerationsUnlocked(array $tags): bool
    {
        $generations = [];
        foreach ($tags as $tag) {
            $generations[$this->mapTag($tag)] = self::newGeneration();
        }

        return $generations === [] || apcu_store($generations) === [];
    }

    /**
     * @param array<string, CacheItemInterface> $items
     * @throws CacheInvalidArgumentException
     * @throws \InvalidArgumentException
     * @throws RuntimeException
     */
    private function saveItemsUnlocked(array $items): bool
    {
        if (!$this->supportsItems($items)) {
            return false;
        }

        $groups = [];
        $expired = [];
        foreach ($items as $item) {
            $expiration = CachePayloadCodec::expirationFromItem($item);
            if ($expiration['ttl'] !== null && $expiration['ttl'] <= 0) {
                $expired[] = $this->map($item->getKey());

                continue;
            }
            $ttl = $expiration['ttl'] ?? 0;
            $groups[$ttl][$this->map($item->getKey())] = $this->encodeItem($item, $expiration['expiresAt']);
        }

        if ($expired !== []) {
            apcu_delete($expired);
        }

        return array_all(
            $groups,
            static fn(array $records, int $ttl): bool => apcu_store($records, null, $ttl) === [],
        );
    }

    /**
     * @throws CacheInvalidArgumentException
     * @throws \InvalidArgumentException
     * @throws RuntimeException
     */
    private function saveUnlocked(CacheItemInterface $item): bool
    {
        if (!$this->supportsItem($item)) {
            throw new CacheInvalidArgumentException('Wrong item type for ApcuCacheAdapter');
        }
        $expires = CachePayloadCodec::expirationFromItem($item);
        $ttl = $expires['ttl'];
        if ($ttl !== null && $ttl <= 0) {
            apcu_delete($this->map($item->getKey()));

            return true;
        }

        $blob = $this->encodeItem($item, $expires['expiresAt']);

        return apcu_store($this->map($item->getKey()), $blob, $ttl ?? 0);
    }

    /** @param array<int|string, string> $generations */
    private function storeTagGenerationsUnlocked(array $generations): bool
    {
        $mapped = [];
        foreach ($generations as $tag => $generation) {
            $tag = (string) $tag;
            if (!self::isGeneration($generation)) {
                return false;
            }
            $mapped[$this->mapTag($tag)] = strtolower($generation);
        }

        return $mapped === [] || apcu_store($mapped) === [];
    }
}
