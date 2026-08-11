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
class ApcuCacheAdapter extends AbstractCacheAdapter implements TagGenerationCacheInterface
{
    private readonly string $ns;

    /**
     * Creates a new APCu cache adapter.
     *
     *
     * @throws RuntimeException If the APCu extension is not enabled.
     * @param string $namespace A namespace prefix to avoid key collisions.
     */
    public function __construct(string $namespace = 'default')
    {
        if (!extension_loaded('apcu') || !apcu_enabled()) {
            throw new RuntimeException('APCu extension is not enabled');
        }
        $this->ns = CacheInput::namespace($namespace);
    }

    public function clear(): bool
    {
        foreach ($this->listKeys() as $apcuKey) {
            apcu_delete($apcuKey);
        }
        $this->deferred = [];

        return true;
    }

    public function deleteItem(string $key): bool
    {
        $mapped = $this->map($key);
        if (!apcu_exists($mapped)) {
            return true;
        }

        return apcu_delete($mapped);
    }

    /**
     * @param array $keys The keys argument.
     * @phpstan-param list<string> $keys
     */
    public function deleteItems(array $keys): bool
    {
        if ($keys === []) {
            return true;
        }

        return apcu_delete(array_map($this->map(...), $keys)) === [];
    }

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

            apcu_delete($apcuKey);
        }

        return new CacheItem($this, $key);
    }

    /** @param list<string> $tags */
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
                    $generation = self::newGeneration();
                    apcu_store($key, $generation);
                }
            }
            $generations[$tag] = $generation;
        }

        return $generations;
    }

    public function hasItem(string $key): bool
    {
        return apcu_exists($this->map($key));
    }

    /**
     * @param array $keys The keys argument.
     * @phpstan-param list<string> $keys
     * @phpstan-return array<string, CacheItem>
     */
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
        $stale = [];
        foreach ($keys as $k) {
            if ($this->appendFetchedHit($items, $stale, $k, $raw)) {
                continue;
            }

            $items[$k] = new CacheItem($this, $k);
        }

        if ($stale !== []) {
            apcu_delete($stale);
        }

        return $items;
    }

    /** @param list<string> $tags */
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

    /** @param list<string> $tags */
    #[\Override]
    public function rotateTagGenerations(array $tags): bool
    {
        $generations = [];
        foreach ($tags as $tag) {
            $generations[$this->mapTag($tag)] = self::newGeneration();
        }

        return $generations === [] || apcu_store($generations) === [];
    }

    public function save(CacheItemInterface $item): bool
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

    /** @param array<string, CacheItemInterface> $items */
    public function saveItems(array $items): bool
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

        foreach ($groups as $ttl => $records) {
            if (apcu_store($records, null, (int) $ttl) !== []) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, string> $generations */
    #[\Override]
    public function storeTagGenerations(array $generations): bool
    {
        $mapped = [];
        foreach ($generations as $tag => $generation) {
            if (!self::isGeneration($generation)) {
                return false;
            }
            $mapped[$this->mapTag($tag)] = strtolower($generation);
        }

        return $mapped === [] || apcu_store($mapped) === [];
    }

    /**
     * @param array $items The items argument.
     * @param array $stale The stale argument.
     * @param string $key The key argument.
     * @param array $raw The raw argument.
     * @phpstan-param array<string, CacheItem> $items
     * @phpstan-param list<string> $stale
     * @phpstan-param array<mixed> $raw
     */
    private function appendFetchedHit(array &$items, array &$stale, string $key, array $raw): bool
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

        $stale[] = $mapped;

        return false;
    }

    private function hitItemFromBlob(string $key, string $blob): ?CacheItem
    {
        $record = $this->decodeRecordFromBlob($blob);
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
        foreach ($iter as $k => $unused) {
            $out[] = $k;
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
}
