<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cache\Adapter;

use Infocyph\CacheLayer\Cache\Item\CacheItem;
use Psr\Cache\CacheItemInterface;
use RuntimeException;

final class SharedMemoryCacheAdapter extends AbstractCacheAdapter
{
    use SecuresFilesystemDirectories;

    private const int VAR_ID = 1;

    /** @var resource */
    private readonly mixed $lockHandle;

    private readonly string $ns;

    private readonly \SysvSharedMemory $segment;

    private readonly string $tokenFile;

    public function __construct(
        string $namespace = 'default',
        int $segmentSize = 16_777_216,
    ) {
        if (!function_exists('shm_attach')) {
            throw new RuntimeException('ext-sysvshm is not available');
        }

        $this->ns = sanitize_cache_ns($namespace);
        $this->tokenFile = $this->createTokenFile();
        $this->segment = $this->attachSegment($segmentSize);
        $this->lockHandle = $this->openLockHandle();

        $this->withExclusiveLock(function (): void {
            if (!shm_has_var($this->segment, self::VAR_ID)) {
                shm_put_var($this->segment, self::VAR_ID, []);
            }
        });
    }

    public function __destruct()
    {
        fclose($this->lockHandle);
        shm_detach($this->segment);
    }

    public function clear(): bool
    {
        $this->deferred = [];

        return $this->withExclusiveLock(
            fn(): bool => shm_put_var($this->segment, self::VAR_ID, []),
        );
    }

    public function count(): int
    {
        return $this->withExclusiveLock(function (): int {
            $store = $this->loadStore();
            $changed = false;
            $count = 0;

            foreach ($store as $key => $blob) {
                if (!str_starts_with($key, $this->ns . ':d:') || !is_string($blob)) {
                    continue;
                }
                $record = $this->decodeRecordFromBlob($blob);
                if ($record === null) {
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
        });
    }

    public function deleteItem(string $key): bool
    {
        $mapped = $this->map($key);

        return $this->withExclusiveLock(function () use ($mapped): bool {
            $store = $this->loadStore();
            unset($store[$mapped]);

            return $this->store($store);
        });
    }

    /**
     * @param array $keys The keys argument.
     * @phpstan-param list<string> $keys
     */
    public function deleteItems(array $keys): bool
    {
        $mappedKeys = [];
        foreach ($keys as $key) {
            $mappedKeys[] = $this->map($key);
        }

        return $this->withExclusiveLock(function () use ($mappedKeys): bool {
            $store = $this->loadStore();
            foreach ($mappedKeys as $mapped) {
                unset($store[$mapped]);
            }

            return $this->store($store);
        });
    }

    public function getItem(string $key): CacheItem
    {
        $mapped = $this->map($key);
        $blob = $this->withSharedLock(
            function () use ($mapped): ?string {
                $value = $this->loadStore()[$mapped] ?? null;

                return is_string($value) ? $value : null;
            },
        );

        return $this->genericFromBlobWithInvalidator(
            $key,
            $blob,
            fn(): bool => $this->deleteItem($key),
        );
    }

    /**
     * @param list<string> $tags
     * @return array<string, int>
     */
    #[\Override]
    public function getTagVersions(array $tags): array
    {
        return $this->withSharedLock(function () use ($tags): array {
            $store = $this->loadStore();
            $versions = [];
            foreach ($tags as $tag) {
                $version = $store[$this->mapTag($tag)] ?? null;
                $versions[$tag] = is_int($version) && $version >= 0 ? $version : 0;
            }

            return $versions;
        });
    }

    public function hasItem(string $key): bool
    {
        return $this->getItem($key)->isHit();
    }

    /** @param list<string> $tags */
    #[\Override]
    public function incrementTagVersions(array $tags): bool
    {
        return $this->withExclusiveLock(function () use ($tags): bool {
            $store = $this->loadStore();
            foreach ($tags as $tag) {
                $key = $this->mapTag($tag);
                $version = $store[$key] ?? null;
                $store[$key] = (is_int($version) && $version >= 0 ? $version : 0) + 1;
            }

            return $this->store($store);
        });
    }

    /**
     * @param list<string> $keys
     * @return array<string, CacheItem>
     */
    public function multiFetch(array $keys): array
    {
        return $this->withExclusiveLock(function () use ($keys): array {
            $store = $this->loadStore();
            $items = [];
            $changed = false;
            foreach ($keys as $key) {
                $mapped = $this->map($key);
                $blob = $store[$mapped] ?? null;
                $record = is_string($blob) ? $this->decodeRecordFromBlob($blob) : null;
                $items[$key] = $record === null
                    ? $this->genericMiss($key)
                    : $this->genericItemFromRecord($key, $record);
                if ($blob !== null && $record === null) {
                    unset($store[$mapped]);
                    $changed = true;
                }
            }
            if ($changed) {
                $this->store($store);
            }

            return $items;
        });
    }

    public function save(CacheItemInterface $item): bool
    {
        return $this->saveEncoded($item, function (CacheItemInterface $saveItem, array $expires): bool {
            $mapped = $this->map($saveItem->getKey());
            $blob = $this->encodeItem($saveItem, $expires['expiresAt']);

            return $this->withExclusiveLock(function () use ($mapped, $blob): bool {
                $store = $this->loadStore();
                $store[$mapped] = $blob;

                return $this->store($store);
            });
        });
    }

    /** @param array<string, CacheItemInterface> $items */
    public function saveItems(array $items): bool
    {
        $records = [];
        $expired = [];
        foreach ($items as $item) {
            if (!$this->supportsItem($item)) {
                return false;
            }
            $expiration = CachePayloadCodec::expirationFromItem($item);
            if ($expiration['ttl'] !== null && $expiration['ttl'] <= 0) {
                $expired[] = $this->map($item->getKey());

                continue;
            }
            $records[$this->map($item->getKey())] = $this->encodeItem($item, $expiration['expiresAt']);
        }

        return $this->withExclusiveLock(function () use ($records, $expired): bool {
            $store = $this->loadStore();
            foreach ($expired as $key) {
                unset($store[$key]);
            }
            foreach ($records as $key => $blob) {
                $store[$key] = $blob;
            }

            return $this->store($store);
        });
    }

    private function attachSegment(int $segmentSize): \SysvSharedMemory
    {
        if (!function_exists('ftok')) {
            throw new RuntimeException('ftok() is required for collision-safe shared-memory keys');
        }
        $projectId = ftok($this->tokenFile, 'C');
        if ($projectId <= 0) {
            throw new RuntimeException('Unable to derive the shared-memory key');
        }

        $segment = shm_attach($projectId, max(1_048_576, $segmentSize), 0600);
        if ($segment === false) {
            throw new RuntimeException('Unable to attach shared-memory segment');
        }

        return $segment;
    }

    private function createTokenFile(): string
    {
        $directory = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . 'cachelayer'
            . DIRECTORY_SEPARATOR
            . 'shared-memory';
        $this->prepareDirectory($directory);
        $tokenFile = $directory . DIRECTORY_SEPARATOR . hash('xxh128', $this->ns) . '.tok';
        if (!is_file($tokenFile)) {
            if (file_put_contents($tokenFile, '', LOCK_EX) === false) {
                throw new RuntimeException('Unable to create the shared-memory token file');
            }
            chmod($tokenFile, 0600);
        }
        if (is_link($tokenFile)) {
            throw new RuntimeException('Refusing a symlinked shared-memory token file');
        }
        $permissions = fileperms($tokenFile);
        if ($permissions !== false && (($permissions & 0x0002) === 0x0002)) {
            throw new RuntimeException('Shared-memory token file must not be world-writable');
        }

        return $tokenFile;
    }

    /**
     * @phpstan-return array<string, string|int>
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
            if (is_string($key) && (is_string($value) || is_int($value))) {
                $out[$key] = $value;
            }
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

    /** @phpstan-return resource */
    private function openLockHandle(): mixed
    {
        $lockHandle = fopen($this->tokenFile, 'c+');
        if (is_resource($lockHandle)) {
            return $lockHandle;
        }

        shm_detach($this->segment);

        throw new RuntimeException('Unable to open the shared-memory lock file');
    }

    private function prepareDirectory(string $directory): void
    {
        $this->assertPathNotSymlink($directory, 'Shared-memory cache directory');
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create the shared-memory cache directory');
        }
        if (!is_writable($directory)) {
            throw new RuntimeException('Shared-memory cache directory is not writable');
        }
        $this->assertSecureDirectory($directory, 'Shared-memory cache directory');
    }

    /**
     * @param array $store The store argument.
     * @phpstan-param array<string, string|int> $store
     */
    private function store(array $store): bool
    {
        return shm_put_var($this->segment, self::VAR_ID, $store);
    }

    /**
     * @template T
     * @param callable $operation The operation to run while locked.
     * @phpstan-param callable(): T $operation
     * @phpstan-return T
     */
    private function withExclusiveLock(callable $operation): mixed
    {
        return $this->withLock(LOCK_EX, $operation);
    }

    /**
     * @template T
     * @param int $operation The flock operation.
     * @param callable $callback The operation to run while locked.
     * @phpstan-param callable(): T $callback
     * @phpstan-return T
     */
    private function withLock(int $operation, callable $callback): mixed
    {
        if ($operation !== LOCK_EX && $operation !== LOCK_SH) {
            throw new RuntimeException('Invalid shared-memory lock operation');
        }
        if (!flock($this->lockHandle, $operation)) {
            throw new RuntimeException('Unable to lock the shared-memory cache');
        }

        try {
            return $callback();
        } finally {
            flock($this->lockHandle, LOCK_UN);
        }
    }

    /**
     * @template T
     * @param callable $operation The operation to run while locked.
     * @phpstan-param callable(): T $operation
     * @phpstan-return T
     */
    private function withSharedLock(callable $operation): mixed
    {
        return $this->withLock(LOCK_SH, $operation);
    }
}
