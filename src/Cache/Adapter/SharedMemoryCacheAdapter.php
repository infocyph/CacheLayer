<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cache\Adapter;

use Infocyph\CacheLayer\Cache\CacheInput;
use Infocyph\CacheLayer\Cache\CacheRecord;
use Infocyph\CacheLayer\Cache\Item\CacheItem;
use Psr\Cache\CacheItemInterface;
use RuntimeException;

final class SharedMemoryCacheAdapter extends AbstractCacheAdapter implements AtomicCachePoolInterface, TagGenerationCacheInterface
{
    use SecuresFilesystemDirectories;

    private const string OWNER_KEY = "\0cachelayer-owner";

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

        $this->ns = CacheInput::namespace($namespace);
        $this->tokenFile = $this->createTokenFile();
        $this->segment = $this->attachSegment($segmentSize);
        $this->lockHandle = $this->openLockHandle();

        $this->withExclusiveLock(function (): void {
            if (!shm_has_var($this->segment, self::VAR_ID)) {
                shm_put_var($this->segment, self::VAR_ID, [self::OWNER_KEY => $this->ownerIdentity()]);
            }
            $this->loadStore();
        });
    }

    public function __destruct()
    {
        fclose($this->lockHandle);
        shm_detach($this->segment);
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
        if ($expiration['ttl'] !== null && $expiration['ttl'] <= 0) {
            return false;
        }

        $mapped = $this->map($key);
        $replacementBlob = $this->encodeItem($replacement, $expiration['expiresAt']);

        return $this->withExclusiveLock(function () use ($mapped, $expected, $replacementBlob): bool {
            $store = $this->loadStore();
            $current = $store[$mapped] ?? null;
            if (!is_string($current)) {
                return false;
            }
            $record = $this->decodeRecordFromBlob($current);
            if (!$record instanceof CacheRecord
                || !$this->recordTagsAreCurrent($record, $store)
                || $record->value !== $expected) {
                return false;
            }

            $store[$mapped] = $replacementBlob;

            return $this->store($store);
        });
    }

    public function atomicGetAndDelete(string $key): CacheItemInterface
    {
        $mapped = $this->map($key);

        return $this->withExclusiveLock(function () use ($key, $mapped): CacheItemInterface {
            $store = $this->loadStore();
            $blob = $store[$mapped] ?? null;
            if (!is_string($blob)) {
                return $this->genericMiss($key);
            }

            $record = $this->decodeRecordFromBlob($blob);
            unset($store[$mapped]);
            if (!$this->store($store)) {
                throw new RuntimeException('Unable to persist shared-memory atomic consume.');
            }
            if (!$record instanceof CacheRecord || !$this->recordTagsAreCurrent($record, $store)) {
                return $this->genericMiss($key);
            }

            return $this->genericItemFromRecord($key, $record);
        });
    }

    public function atomicSetIfAbsent(CacheItemInterface $item): bool
    {
        if (!$this->supportsItem($item)) {
            return false;
        }
        $expiration = CachePayloadCodec::expirationFromItem($item);
        if ($expiration['ttl'] !== null && $expiration['ttl'] <= 0) {
            return false;
        }

        $mapped = $this->map($item->getKey());
        $blob = $this->encodeItem($item, $expiration['expiresAt']);

        return $this->withExclusiveLock(function () use ($mapped, $blob): bool {
            $store = $this->loadStore();
            $existing = $store[$mapped] ?? null;
            if (is_string($existing)) {
                $record = $this->decodeRecordFromBlob($existing);
                if ($record instanceof CacheRecord && $this->recordTagsAreCurrent($record, $store)) {
                    return false;
                }
            }

            $store[$mapped] = $blob;

            return $this->store($store);
        });
    }

    public function clear(): bool
    {
        $this->deferred = [];

        return $this->withExclusiveLock(
            fn(): bool => shm_put_var(
                $this->segment,
                self::VAR_ID,
                [self::OWNER_KEY => $this->ownerIdentity()],
            ),
        );
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
     * @return array<string, string>
     */
    #[\Override]
    public function getTagGenerations(array $tags): array
    {
        $generations = $this->readTagGenerations($tags);
        $missing = [];
        foreach ($tags as $tag) {
            if (!isset($generations[$tag])) {
                $missing[$tag] = self::newGeneration();
            }
        }
        if ($missing !== []) {
            $this->storeTagGenerations($missing);
        }

        return $generations + $missing;
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
        [$items, $invalid] = $this->withSharedLock(function () use ($keys): array {
            $store = $this->loadStore();
            $items = [];
            $invalid = [];
            foreach ($keys as $key) {
                $mapped = $this->map($key);
                $blob = $store[$mapped] ?? null;
                $record = is_string($blob) ? $this->decodeRecordFromBlob($blob) : null;
                $items[$key] = $record === null
                    ? $this->genericMiss($key)
                    : $this->genericItemFromRecord($key, $record);
                if ($blob !== null && $record === null) {
                    $invalid[] = $key;
                }
            }

            return [$items, $invalid];
        });
        if ($invalid !== []) {
            $this->deleteItems($invalid);
        }

        return $items;
    }

    /** @param list<string> $tags */
    #[\Override]
    public function readTagGenerations(array $tags): array
    {
        return $this->withSharedLock(function () use ($tags): array {
            $store = $this->loadStore();
            $generations = [];
            foreach ($tags as $tag) {
                $generation = $store[$this->mapTag($tag)] ?? null;
                if (self::isGeneration($generation)) {
                    $generations[$tag] = strtolower((string) $generation);
                }
            }

            return $generations;
        });
    }

    /** @param list<string> $tags */
    #[\Override]
    public function rotateTagGenerations(array $tags): bool
    {
        $generations = [];
        foreach ($tags as $tag) {
            $generations[$tag] = self::newGeneration();
        }

        return $this->storeTagGenerations($generations);
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

    /** @param array<string, string> $generations */
    #[\Override]
    public function storeTagGenerations(array $generations): bool
    {
        return $this->withExclusiveLock(function () use ($generations): bool {
            $store = $this->loadStore();
            foreach ($generations as $tag => $generation) {
                if (!self::isGeneration($generation)) {
                    return false;
                }
                $store[$this->mapTag($tag)] = strtolower($generation);
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
     * @phpstan-return array<string, string>
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
        if (($out[self::OWNER_KEY] ?? null) !== $this->ownerIdentity()) {
            throw new RuntimeException('Shared-memory key collision detected');
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

    private function ownerIdentity(): string
    {
        return hash('xxh128', self::class . "\0" . $this->ns . "\0" . $this->tokenFile);
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

    /** @param array<string, string> $store */
    private function recordTagsAreCurrent(CacheRecord $record, array $store): bool
    {
        foreach ($record->tags as $tag => $generation) {
            if (($store[$this->mapTag($tag)] ?? null) !== $generation) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array $store The store argument.
     * @phpstan-param array<string, string> $store
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
