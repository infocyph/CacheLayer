<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cache\Adapter;

use Infocyph\CacheLayer\Cache\CacheInput;
use Infocyph\CacheLayer\Cache\CacheRecord;
use Infocyph\CacheLayer\Cache\Item\CacheItem;
use Psr\Cache\CacheItemInterface;
use RuntimeException;

final class PhpFilesCacheAdapter extends AbstractCacheAdapter implements AtomicCachePoolInterface
{
    use SecuresFilesystemDirectories;

    private const string DEFAULT_BASE_DIR = 'cachelayer/phpfiles';

    private string $dataDirectory;

    private string $lockDirectory;

    private string $metadataDirectory;

    public function __construct(string $namespace = 'default', ?string $baseDir = null)
    {
        $this->createDirectory($namespace, $baseDir);
    }

    public function atomicCompareAndSet(string $key, mixed $expected, CacheItemInterface $replacement): bool
    {
        if (!$this->supportsItem($replacement)) {
            return false;
        }
        $expiration = CachePayloadCodec::expirationFromItem($replacement);
        if ($expiration['ttl'] !== null && $expiration['ttl'] <= 0) {
            return false;
        }

        return $this->withKeyLock($key, function () use ($key, $expected, $replacement): bool {
            $record = $this->readLiveRecordUnlocked($key);
            if (!$record instanceof CacheRecord || $record->value !== $expected) {
                return false;
            }

            return $this->persistItemUnlocked($replacement);
        });
    }

    public function atomicGetAndDelete(string $key): CacheItemInterface
    {
        return $this->withKeyLock($key, function () use ($key): CacheItemInterface {
            $record = $this->readLiveRecordUnlocked($key);
            if (!$record instanceof CacheRecord) {
                return $this->genericMiss($key);
            }
            $this->deleteItemUnlocked($key);

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

        return $this->withKeyLock($item->getKey(), function () use ($item): bool {
            if ($this->readLiveRecordUnlocked($item->getKey()) instanceof CacheRecord) {
                return false;
            }

            return $this->persistItemUnlocked($item);
        });
    }

    public function clear(): bool
    {
        $ok = true;
        foreach (glob($this->dataDirectory . '*.php') ?: [] as $file) {
            $this->invalidateOpcache($file);
            $ok = (!is_file($file) || unlink($file)) && $ok;
        }
        foreach (glob($this->metadataDirectory . '*') ?: [] as $file) {
            $ok = (!is_file($file) || unlink($file)) && $ok;
        }
        $this->deferred = [];

        return $ok;
    }

    public function deleteItem(string $key): bool
    {
        return $this->withKeyLock($key, fn(): bool => $this->deleteItemUnlocked($key));
    }

    /** @param list<string> $keys */
    public function deleteItems(array $keys): bool
    {
        $ok = true;
        foreach ($keys as $key) {
            $ok = $this->deleteItem($key) && $ok;
        }

        return $ok;
    }

    public function getItem(string $key): CacheItem
    {
        $record = $this->readLiveRecordUnlocked($key);
        if ($record instanceof CacheRecord) {
            return $this->genericItemFromRecord($key, $record);
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
        $generations = [];
        foreach ($tags as $tag) {
            $path = $this->metadataFileFor($tag);
            $value = is_file($path) ? file_get_contents($path) : false;
            if (!self::isGeneration($value)) {
                $value = self::newGeneration();
                if (!$this->atomicReplace($path, $value)) {
                    throw new RuntimeException('Unable to initialize PHP-file tag generation.');
                }
            }
            $generations[$tag] = strtolower((string) $value);
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
        $items = [];
        foreach ($keys as $key) {
            $items[$key] = $this->getItem($key);
        }

        return $items;
    }

    /** @param list<string> $tags */
    #[\Override]
    public function rotateTagGenerations(array $tags): bool
    {
        foreach ($tags as $tag) {
            if (!$this->atomicReplace($this->metadataFileFor($tag), self::newGeneration())) {
                return false;
            }
        }

        return true;
    }

    public function save(CacheItemInterface $item): bool
    {
        if (!$this->supportsItem($item)) {
            return false;
        }

        return $this->persistItem($item);
    }

    /** @param array<string, CacheItemInterface> $items */
    public function saveItems(array $items): bool
    {
        if (!$this->supportsItems($items)) {
            return false;
        }

        $ok = true;
        foreach ($items as $item) {
            $ok = $this->persistItem($item) && $ok;
        }

        return $ok;
    }

    private function createDirectory(string $ns, ?string $baseDir): void
    {
        $baseDir = rtrim($baseDir ?? $this->defaultBaseDirectory(), DIRECTORY_SEPARATOR);
        $ns = CacheInput::namespace($ns);
        $root = $baseDir . DIRECTORY_SEPARATOR . 'cache_' . $ns . DIRECTORY_SEPARATOR;
        $this->dataDirectory = $root . 'data' . DIRECTORY_SEPARATOR;
        $this->metadataDirectory = $root . 'meta' . DIRECTORY_SEPARATOR;
        $this->lockDirectory = $root . 'locks' . DIRECTORY_SEPARATOR;

        foreach ([$baseDir, $this->dataDirectory, $this->metadataDirectory, $this->lockDirectory] as $directory) {
            $this->assertPathNotSymlink($directory, 'PHP cache directory');
            if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
                throw new RuntimeException("Unable to create PHP cache directory: {$directory}");
            }
            $this->assertSecureDirectory($directory, 'PHP cache directory');
        }
        if (!is_writable($this->dataDirectory) || !is_writable($this->metadataDirectory) || !is_writable($this->lockDirectory)) {
            throw new RuntimeException('PHP cache directories are not writable.');
        }
    }

    private function defaultBaseDirectory(): string
    {
        return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, self::DEFAULT_BASE_DIR);
    }

    private function deleteItemUnlocked(string $key): bool
    {
        $file = $this->fileFor($key);
        $this->invalidateOpcache($file);

        return !is_file($file) || unlink($file);
    }

    private function fileFor(string $key): string
    {
        return $this->dataDirectory . hash('xxh128', $key) . '.php';
    }

    private function invalidateOpcache(string $file): void
    {
        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($file, true);
        }
    }

    private function metadataFileFor(string $tag): string
    {
        return $this->metadataDirectory . hash('xxh128', $tag) . '.generation';
    }

    private function persistItem(CacheItemInterface $item): bool
    {
        return $this->withKeyLock($item->getKey(), fn(): bool => $this->persistItemUnlocked($item));
    }

    private function persistItemUnlocked(CacheItemInterface $item): bool
    {
        $expires = CachePayloadCodec::expirationFromItem($item);
        if ($expires['ttl'] !== null && $expires['ttl'] <= 0) {
            return $this->deleteItemUnlocked($item->getKey());
        }

        $blob = $this->encodeItem($item, $expires['expiresAt']);
        $payload = var_export(base64_encode($blob), true);
        $code = "<?php\n\nreturn ['p' => {$payload}];\n";
        $file = $this->fileFor($item->getKey());
        $tmp = tempnam($this->dataDirectory, 'pc_');
        if ($tmp === false) {
            return false;
        }
        if (file_put_contents($tmp, $code, LOCK_EX) === false) {
            if (is_file($tmp)) {
                unlink($tmp);
            }

            return false;
        }
        $this->invalidateOpcache($file);
        if (!rename($tmp, $file)) {
            if (is_file($tmp)) {
                unlink($tmp);
            }

            return false;
        }

        return true;
    }

    private function readLiveRecordUnlocked(string $key): ?CacheRecord
    {
        $file = $this->fileFor($key);
        if (!is_file($file)) {
            return null;
        }
        $row = require $file;
        $payload = is_array($row) && is_string($row['p'] ?? null) ? $row['p'] : null;
        $blob = is_string($payload) ? base64_decode($payload, true) : false;
        $record = is_string($blob) ? $this->decodeRecordFromBlob($blob) : null;
        if (!$record instanceof CacheRecord || !$this->recordTagsAreCurrent($record)) {
            return null;
        }

        return $record;
    }

    private function recordTagsAreCurrent(CacheRecord $record): bool
    {
        foreach ($record->tags as $tag => $generation) {
            $current = is_file($this->metadataFileFor($tag))
                ? file_get_contents($this->metadataFileFor($tag))
                : false;
            if (!is_string($current) || strtolower($current) !== $generation) {
                return false;
            }
        }

        return true;
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    private function withKeyLock(string $key, callable $callback): mixed
    {
        $path = $this->lockDirectory . hash('xxh128', $key) . '.lock';
        $handle = fopen($path, 'c');
        if (!is_resource($handle) || !flock($handle, LOCK_EX)) {
            if (is_resource($handle)) {
                fclose($handle);
            }

            throw new RuntimeException('Unable to acquire PHP-file cache key lock.');
        }

        try {
            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
