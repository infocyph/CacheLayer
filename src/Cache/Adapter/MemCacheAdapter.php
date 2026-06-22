<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cache\Adapter;

use Infocyph\CacheLayer\Cache\Item\MemCacheItem;
use Infocyph\CacheLayer\Exceptions\CacheInvalidArgumentException;
use Psr\Cache\CacheItemInterface;
use RuntimeException;

class MemCacheAdapter extends AbstractCacheAdapter
{
    private readonly \Memcached $mc;

    private readonly string $ns;

    /** @var array<string, bool> */
    private array $knownKeys = [];

    /**
     * @param string $namespace The namespace argument.
     * @param array $servers The servers argument.
     * @param \Memcached|null $client The client argument.
     * @phpstan-param array<int, array{0:string,1:int,2:int}> $servers
     */
    public function __construct(
        string $namespace = 'default',
        array $servers = [['127.0.0.1', 11211, 0]],
        ?\Memcached $client = null,
    ) {
        if (!class_exists(\Memcached::class)) {
            throw new RuntimeException('Memcached extension not loaded');
        }

        $this->ns = sanitize_cache_ns($namespace);
        $this->mc = $client ?? new \Memcached();
        if (!$client) {
            $this->mc->addServers($servers);
        }
    }

    public function clear(): bool
    {
        $this->mc->flush();
        $this->deferred = [];
        $this->knownKeys = [];

        return true;
    }

    public function count(): int
    {
        return count($this->fetchKeys());
    }

    public function deleteItem(string $key): bool
    {
        $this->mc->delete($this->map($key));
        unset($this->knownKeys[$key]);

        return true;
    }

    /**
     * @param array $keys The keys argument.
     * @phpstan-param list<string> $keys
     */
    public function deleteItems(array $keys): bool
    {
        foreach ($keys as $k) {
            $this->deleteItem($k);
        }

        return true;
    }

    public function getClient(): \Memcached
    {
        return $this->mc;
    }

    public function getItem(string $key): MemCacheItem
    {
        $mappedKey = $this->map($key);
        $raw = $this->mc->get($mappedKey);
        if ($this->mc->getResultCode() === \Memcached::RES_SUCCESS && is_string($raw)) {
            $item = $this->hitItemFromBlob($key, $raw);
            if ($item instanceof MemCacheItem) {
                return $item;
            }

            $this->mc->delete($mappedKey);
            unset($this->knownKeys[$key]);
        }

        return $this->missItem($key);
    }

    public function hasItem(string $key): bool
    {
        $this->mc->get($this->map($key));

        return $this->mc->getResultCode() === \Memcached::RES_SUCCESS;
    }

    /**
     * @param array $keys The keys argument.
     * @phpstan-param list<string> $keys
     * @phpstan-return array<string, MemCacheItem>
     */
    public function multiFetch(array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        $prefixed = array_map($this->map(...), $keys);
        $raw = $this->mc->getMulti($prefixed, \Memcached::GET_PRESERVE_ORDER);
        if (!is_array($raw)) {
            $raw = [];
        }

        $items = [];
        $stale = [];
        $staleLogicalKeys = [];
        foreach ($keys as $k) {
            $p = $this->map($k);
            if (isset($raw[$p]) && $this->appendFetchedHit($items, $stale, $staleLogicalKeys, $k, $p, $raw[$p])) {
                continue;
            }

            $items[$k] = $this->missItem($k);
        }

        if ($stale !== []) {
            $this->mc->deleteMulti($stale);
            foreach ($staleLogicalKeys as $key) {
                unset($this->knownKeys[$key]);
            }
        }

        return $items;
    }

    public function save(CacheItemInterface $item): bool
    {
        if (!$this->supportsItem($item)) {
            throw new CacheInvalidArgumentException('Wrong item class');
        }

        $expires = CachePayloadCodec::expirationFromItem($item);
        $ttl = $expires['ttl'];
        if ($ttl === 0) {
            $this->mc->delete($this->map($item->getKey()));
            unset($this->knownKeys[$item->getKey()]);

            return true;
        }

        $blob = CachePayloadCodec::encode($item->get(), $expires['expiresAt']);
        $ok = $this->mc->set($this->map($item->getKey()), $blob, $ttl ?? 0);
        if ($ok) {
            $this->knownKeys[$item->getKey()] = true;
        }

        return $ok;
    }

    protected function supportsItem(CacheItemInterface $item): bool
    {
        return $item instanceof MemCacheItem;
    }

    /**
     * @param array $items The items argument.
     * @param array $stale The stale argument.
     * @param array $staleLogicalKeys The stale logical keys argument.
     * @param string $logicalKey The logical key argument.
     * @param string $mappedKey The mapped key argument.
     * @param mixed $rawEntry The raw entry argument.
     * @phpstan-param array<string, MemCacheItem> $items
     * @phpstan-param list<string> $stale
     * @phpstan-param list<string> $staleLogicalKeys
     */
    private function appendFetchedHit(
        array &$items,
        array &$stale,
        array &$staleLogicalKeys,
        string $logicalKey,
        string $mappedKey,
        mixed $rawEntry,
    ): bool {
        if (!is_string($rawEntry)) {
            return false;
        }

        $item = $this->hitItemFromBlob($logicalKey, $rawEntry);
        if ($item instanceof MemCacheItem) {
            $items[$logicalKey] = $item;

            return true;
        }

        $stale[] = $mappedKey;
        $staleLogicalKeys[] = $logicalKey;

        return false;
    }

    /**
     * @param string $server The server argument.
     * @param int $slabId The slab id argument.
     * @param string $pref The pref argument.
     * @param array $seen The seen argument.
     * @param array $out The out argument.
     * @phpstan-param array<string, bool> $seen
     * @phpstan-param list<string> $out
     */
    private function collectDumpedKeys(
        string $server,
        int $slabId,
        string $pref,
        array &$seen,
        array &$out,
    ): void {
        $dump = $this->mc->getStats("cachedump $slabId 0");

        if (!isset($dump[$server]) || !is_array($dump[$server])) {
            return;
        }

        $keys = $this->stripNamespace(array_values(array_filter(
            array_map(strval(...), array_keys($dump[$server])),
            static fn(string $value): bool => $value !== '',
        )), $pref);

        foreach ($keys as $key) {
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $out[] = $key;
        }
    }

    /**
     * @param array $items The items argument.
     * @phpstan-param array<mixed> $items
     * @phpstan-return list<int>
     */
    private function extractSlabIds(array $items): array
    {
        $ids = [];

        foreach ($items as $name => $value) {
            if (!preg_match('/items:(\d+):number/', (string) $name, $m)) {
                continue;
            }

            $ids[] = (int) $m[1];
        }

        return array_values(array_unique($ids));
    }

    /**
     * @phpstan-return list<string>
     */
    private function fastKnownKeys(): array
    {
        return $this->knownKeys ? array_keys($this->knownKeys) : [];
    }

    /**
     * @phpstan-return list<string>
     */
    private function fetchKeys(): array
    {
        if ($quick = $this->fastKnownKeys()) {
            return $quick;
        }

        $pref = $this->ns . ':';
        if ($keys = $this->keysFromGetAll($pref)) {
            return $keys;
        }

        return $this->keysFromSlabDump($pref);
    }

    private function hitItemFromBlob(string $key, string $blob): ?MemCacheItem
    {
        $record = $this->decodeRecordFromBlob($blob);
        if ($record === null) {
            return null;
        }

        return new MemCacheItem(
            $this,
            $key,
            $record['value'],
            true,
            CachePayloadCodec::toDateTime($record['expires']),
        );
    }

    /**
     * @phpstan-return list<string>
 * @param string $pref The pref argument.
     */
    private function keysFromGetAll(string $pref): array
    {
        $all = $this->mc->getAllKeys();
        if (!is_array($all)) {
            return [];
        }

        $keys = [];
        foreach ($all as $key) {
            if (is_string($key)) {
                $keys[] = $key;
            }
        }

        return $this->stripNamespace($keys, $pref);
    }

    /**
     * @phpstan-return list<string>
 * @param string $pref The pref argument.
     */
    private function keysFromSlabDump(string $pref): array
    {
        /** @var list<string> $out */
        $out = [];
        /** @var array<string, bool> $seen */
        $seen = [];

        foreach ($this->slabIdsByServer() as $server => $slabIds) {
            foreach ($slabIds as $slabId) {
                $this->collectDumpedKeys($server, $slabId, $pref, $seen, $out);
            }
        }

        return $out;
    }

    private function map(string $key): string
    {
        return $this->ns . ':' . $key;
    }

    private function missItem(string $key): MemCacheItem
    {
        return new MemCacheItem($this, $key);
    }

    /**
     * @phpstan-return array<string, list<int>>
     */
    private function slabIdsByServer(): array
    {
        $stats = $this->mc->getStats('items');

        $mapped = [];
        foreach ($stats as $server => $items) {
            if (!is_string($server) || !is_array($items)) {
                continue;
            }

            $mapped[$server] = $this->extractSlabIds($items);
        }

        return $mapped;
    }

    /**
     * @param array $fullKeys The full keys argument.
     * @param string $pref The pref argument.
     * @phpstan-param array<int|string, string> $fullKeys
     * @phpstan-return list<string>
     */
    private function stripNamespace(array $fullKeys, string $pref): array
    {
        return array_values(array_map(
            fn(string $k) => substr($k, strlen($pref)),
            array_filter($fullKeys, fn(string $k) => str_starts_with($k, $pref)),
        ));
    }
}
