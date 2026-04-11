<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cache\Adapter;

use Infocyph\CacheLayer\Cache\Item\GenericCacheItem;
use Psr\Cache\CacheItemInterface;
use RuntimeException;

final class SharedMemoryCacheAdapter extends AbstractCacheAdapter
{
    private const int VAR_ID = 1;
    private readonly string $ns;
    private readonly mixed $segment;
    private readonly string $tokenFile;

    public function __construct(
        string $namespace = 'default',
        int $segmentSize = 16_777_216,
    ) {
        if (!function_exists('shm_attach')) {
            throw new RuntimeException('ext-sysvshm is not available');
        }

        $this->ns = sanitize_cache_ns($namespace);
        $this->tokenFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cachelayer_shm_' . $this->ns . '.tok';
        if (!is_file($this->tokenFile)) {
            @touch($this->tokenFile);
        }

        $projectId = function_exists('ftok') ? ftok($this->tokenFile, 'C') : false;
        $shmKey = is_int($projectId) && $projectId > 0
            ? $projectId
            : abs(crc32('cachelayer:' . $this->ns));

        $segment = @shm_attach($shmKey, max(1_048_576, $segmentSize), 0666);
        if ($segment === false) {
            throw new RuntimeException('Unable to attach shared-memory segment');
        }

        $this->segment = $segment;
        if (!shm_has_var($this->segment, self::VAR_ID)) {
            shm_put_var($this->segment, self::VAR_ID, []);
        }
    }

    public function clear(): bool
    {
        $this->deferred = [];
        return shm_put_var($this->segment, self::VAR_ID, []);
    }

    public function count(): int
    {
        $store = $this->loadStore();
        $changed = false;
        $count = 0;

        foreach ($store as $key => $blob) {
            $record = CachePayloadCodec::decode((string) $blob);
            if ($record === null || CachePayloadCodec::isExpired($record['expires'])) {
                unset($store[$key]);
                $changed = true;
                continue;
            }

            $count++;
        }

        if ($changed) {
            $this->store($store);
        }

        return $count;
    }

    public function deleteItem(string $key): bool
    {
        $store = $this->loadStore();
        unset($store[$this->map($key)]);
        return $this->store($store);
    }

    public function deleteItems(array $keys): bool
    {
        $store = $this->loadStore();
        foreach ($keys as $key) {
            unset($store[$this->map((string) $key)]);
        }

        return $this->store($store);
    }

    public function getItem(string $key): GenericCacheItem
    {
        $mapped = $this->map($key);
        $store = $this->loadStore();
        $blob = $store[$mapped] ?? null;
        if (!is_string($blob)) {
            return new GenericCacheItem($this, $key);
        }

        $record = CachePayloadCodec::decode($blob);
        if ($record === null || CachePayloadCodec::isExpired($record['expires'])) {
            unset($store[$mapped]);
            $this->store($store);
            return new GenericCacheItem($this, $key);
        }

        $item = new GenericCacheItem($this, $key);
        $item->set($record['value']);
        if ($record['expires'] !== null) {
            $item->expiresAt(CachePayloadCodec::toDateTime($record['expires']));
        }

        return $item;
    }

    public function hasItem(string $key): bool
    {
        return $this->getItem($key)->isHit();
    }

    public function multiFetch(array $keys): array
    {
        $items = [];
        foreach ($keys as $key) {
            $items[(string) $key] = $this->getItem((string) $key);
        }

        return $items;
    }

    public function save(CacheItemInterface $item): bool
    {
        if (!$this->supportsItem($item)) {
            return false;
        }

        $expires = CachePayloadCodec::expirationFromItem($item);
        if ($expires['ttl'] === 0) {
            return $this->deleteItem($item->getKey());
        }

        $store = $this->loadStore();
        $store[$this->map($item->getKey())] = CachePayloadCodec::encode($item->get(), $expires['expiresAt']);
        return $this->store($store);
    }

    protected function supportsItem(CacheItemInterface $item): bool
    {
        return $item instanceof GenericCacheItem;
    }

    private function loadStore(): array
    {
        if (!shm_has_var($this->segment, self::VAR_ID)) {
            return [];
        }

        $store = shm_get_var($this->segment, self::VAR_ID);
        return is_array($store) ? $store : [];
    }

    private function map(string $key): string
    {
        return $this->ns . ':' . $key;
    }

    private function store(array $store): bool
    {
        return shm_put_var($this->segment, self::VAR_ID, $store);
    }
}
