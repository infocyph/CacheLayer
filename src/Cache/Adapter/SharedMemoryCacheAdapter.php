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

    /**
     * @param list<string> $keys
     */
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

        return $this->genericFromBlobWithInvalidator(
            $key,
            is_string($blob) ? $blob : null,
            function () use (&$store, $mapped): bool {
                unset($store[$mapped]);

                return $this->store($store);
            },
        );
    }

    public function hasItem(string $key): bool
    {
        return $this->getItem($key)->isHit();
    }

    /**
     * @param list<string> $keys
     * @return array<string, GenericCacheItem>
     */
    public function multiFetch(array $keys): array
    {
        return $this->multiFetchItems($keys, $this->getItem(...));
    }

    public function save(CacheItemInterface $item): bool
    {
        return $this->saveEncoded($item, function (CacheItemInterface $saveItem, array $expires): bool {
            $store = $this->loadStore();
            $store[$this->map($saveItem->getKey())] = CachePayloadCodec::encode($saveItem->get(), $expires['expiresAt']);

            return $this->store($store);
        });
    }

    protected function supportsItem(CacheItemInterface $item): bool
    {
        return $item instanceof GenericCacheItem;
    }

    /**
     * @return array<string, string>
     */
    private function loadStore(): array
    {
        if (!shm_has_var($this->segment, self::VAR_ID)) {
            return [];
        }

        $store = shm_get_var($this->segment, self::VAR_ID);

        if (!is_array($store)) {
            return [];
        }

        $out = [];
        foreach ($store as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    private function map(string $key): string
    {
        return $this->ns . ':' . $key;
    }

    /**
     * @param array<string, string> $store
     */
    private function store(array $store): bool
    {
        return shm_put_var($this->segment, self::VAR_ID, $store);
    }
}
