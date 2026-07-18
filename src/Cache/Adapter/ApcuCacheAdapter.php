<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cache\Adapter;

use Infocyph\CacheLayer\Cache\Item\ApcuCacheItem;
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
class ApcuCacheAdapter extends AbstractCacheAdapter
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
        $this->ns = sanitize_cache_ns($namespace);
    }

    public function clear(): bool
    {
        foreach ($this->listKeys() as $apcuKey) {
            apcu_delete($apcuKey);
        }
        $this->deferred = [];

        return true;
    }

    public function count(): int
    {
        return count($this->listKeys());
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
        $ok = true;
        foreach ($keys as $k) {
            $ok = $ok && $this->deleteItem($k);
        }

        return $ok;
    }

    public function getItem(string $key): ApcuCacheItem
    {
        $apcuKey = $this->map($key);
        $success = false;
        $raw = apcu_fetch($apcuKey, $success);

        if ($success && is_string($raw)) {
            $item = $this->hitItemFromBlob($key, $raw);
            if ($item instanceof ApcuCacheItem) {
                return $item;
            }

            apcu_delete($apcuKey);
        }

        return new ApcuCacheItem($this, $key);
    }

    public function hasItem(string $key): bool
    {
        return apcu_exists($this->map($key));
    }

    /**
     * @param array $keys The keys argument.
     * @phpstan-param list<string> $keys
     * @phpstan-return array<string, ApcuCacheItem>
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

            $items[$k] = new ApcuCacheItem($this, $k);
        }

        if ($stale !== []) {
            apcu_delete($stale);
        }

        return $items;
    }

    public function save(CacheItemInterface $item): bool
    {
        if (!$this->supportsItem($item)) {
            throw new CacheInvalidArgumentException('Wrong item type for ApcuCacheAdapter');
        }
        $expires = CachePayloadCodec::expirationFromItem($item);
        $ttl = $expires['ttl'];
        if ($ttl === 0) {
            apcu_delete($this->map($item->getKey()));

            return true;
        }

        $blob = CachePayloadCodec::encode($item->get(), $expires['expiresAt']);

        return apcu_store($this->map($item->getKey()), $blob, $ttl ?? 0);
    }

    protected function supportsItem(CacheItemInterface $item): bool
    {
        return $item instanceof ApcuCacheItem;
    }

    /**
     * @param array $items The items argument.
     * @param array $stale The stale argument.
     * @param string $key The key argument.
     * @param array $raw The raw argument.
     * @phpstan-param array<string, ApcuCacheItem> $items
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
        if ($item instanceof ApcuCacheItem) {
            $items[$key] = $item;

            return true;
        }

        $stale[] = $mapped;

        return false;
    }

    private function hitItemFromBlob(string $key, string $blob): ?ApcuCacheItem
    {
        $record = $this->decodeRecordFromBlob($blob);
        if ($record === null) {
            return null;
        }

        $expiresAt = CachePayloadCodec::toDateTime($record['expires']);

        return new ApcuCacheItem(
            pool: $this,
            key: $key,
            value: $record['value'],
            hit: true,
            exp: $expiresAt,
        );
    }

    /**
     * @phpstan-return list<string>
     */
    private function listKeys(): array
    {
        $iter = new \APCUIterator(
            '/^' . preg_quote($this->ns . ':', '/') . '/',
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
        return $this->ns . ':' . $key;
    }
}
