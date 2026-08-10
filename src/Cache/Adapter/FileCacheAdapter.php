<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cache\Adapter;

use Infocyph\CacheLayer\Cache\Item\CacheItem;
use Infocyph\CacheLayer\Exceptions\CacheInvalidArgumentException;
use Psr\Cache\CacheItemInterface;
use RuntimeException;

/**
 * File-based cache adapter implementation.
 *
 * This adapter stores cache data as individual files in a specified directory.
 * Each cache entry is serialized and stored with a .cache extension.
 * It provides a simple filesystem-based caching solution suitable for
 * development environments or applications without access to dedicated cache systems.
     * @param string $namespace A namespace prefix for cache files to avoid collisions.
     * @param string|null $baseDir The base directory for cache files. If null, uses system temp directory.
 */
class FileCacheAdapter extends AbstractCacheAdapter
{
    use SecuresFilesystemDirectories;

    private const string DEFAULT_BASE_DIR = 'cachelayer/files';

    private string $dataDirectory;

    private string $metadataDirectory;

    /**
     * Creates a new file-based cache adapter.
     *
     *
     * @throws RuntimeException If the cache directory cannot be created or is not writable.
     * @param string $namespace A namespace prefix for cache files to avoid collisions.
     * @param string|null $baseDir The base directory for cache files. If null, uses system temp directory.
     */
    public function __construct(string $namespace = 'default', ?string $baseDir = null)
    {
        $this->createDirectory($namespace, $baseDir);
    }

    public function clear(): bool
    {
        $ok = true;
        foreach ([$this->dataDirectory, $this->metadataDirectory] as $directory) {
            foreach (glob($directory . '*') ?: [] as $file) {
                $ok = (!is_file($file) || unlink($file)) && $ok;
            }
        }
        $this->deferred = [];

        return $ok;
    }

    public function count(): int
    {
        return iterator_count(new \FilesystemIterator($this->dataDirectory, \FilesystemIterator::SKIP_DOTS));
    }

    public function deleteItem(string $key): bool
    {
        $file = $this->fileFor($key);

        return !is_file($file) || unlink($file);
    }

    /**
     * @param array $keys The keys argument.
     * @phpstan-param list<string> $keys
     */
    public function deleteItems(array $keys): bool
    {
        $ok = true;
        foreach ($keys as $k) {
            $ok = $this->deleteItem($k) && $ok;
        }

        return $ok;
    }

    public function getItem(string $key): CacheItem
    {
        $file = $this->fileFor($key);

        if (is_file($file)) {
            $raw = file_get_contents($file);
            if (is_string($raw)) {
                $record = $this->decodeRecordFromBlob($raw);
                if ($record !== null) {
                    return $this->genericItemFromRecord($key, $record);
                }
            }
            unlink($file);
        }

        return new CacheItem($this, $key);
    }

    /** @param list<string> $tags */
    #[\Override]
    public function getTagVersions(array $tags): array
    {
        $versions = [];
        foreach ($tags as $tag) {
            $path = $this->metadataFileFor($tag);
            $value = is_file($path) ? file_get_contents($path) : false;
            $versions[$tag] = is_string($value) && ctype_digit($value) ? (int) $value : 0;
        }

        return $versions;
    }

    public function hasItem(string $key): bool
    {
        return $this->getItem($key)->isHit();
    }

    /** @param list<string> $tags */
    #[\Override]
    public function incrementTagVersions(array $tags): bool
    {
        foreach ($tags as $tag) {
            $path = $this->metadataFileFor($tag);
            $handle = fopen($path, 'c+');
            if (!is_resource($handle) || !flock($handle, LOCK_EX)) {
                if (is_resource($handle)) {
                    fclose($handle);
                }

                return false;
            }
            $raw = stream_get_contents($handle);
            $version = is_string($raw) && ctype_digit($raw) ? (int) $raw : 0;
            rewind($handle);
            ftruncate($handle, 0);
            $written = fwrite($handle, (string) ($version + 1));
            fflush($handle);
            flock($handle, LOCK_UN);
            fclose($handle);
            if ($written === false) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<string> $keys
     * @return array<string, CacheItem>
     */
    public function multiFetch(array $keys): array
    {
        $items = [];
        $stale = [];
        foreach ($keys as $key) {
            $file = $this->fileFor($key);
            $raw = is_file($file) ? file_get_contents($file) : false;
            if (!is_string($raw)) {
                $items[$key] = $this->genericMiss($key);

                continue;
            }
            $record = $this->decodeRecordFromBlob($raw);
            if ($record === null) {
                $stale[] = $file;
                $items[$key] = $this->genericMiss($key);

                continue;
            }
            $items[$key] = $this->genericItemFromRecord($key, $record);
        }
        foreach ($stale as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        return $items;
    }

    public function save(CacheItemInterface $item): bool
    {
        if (!$this->supportsItem($item)) {
            throw new CacheInvalidArgumentException('Invalid item type for FileCacheAdapter');
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

    private function assertWritableDirectory(string $path, string $message): void
    {
        if (!is_writable($path)) {
            throw new RuntimeException($message);
        }
    }

    private function createDirectory(string $ns, ?string $baseDir): void
    {
        $baseDir = rtrim($baseDir ?? $this->defaultBaseDirectory(), DIRECTORY_SEPARATOR);
        $ns = sanitize_cache_ns($ns);
        $root = $baseDir . DIRECTORY_SEPARATOR . 'cache_' . $ns . DIRECTORY_SEPARATOR;
        $this->dataDirectory = $root . 'data' . DIRECTORY_SEPARATOR;
        $this->metadataDirectory = $root . 'meta' . DIRECTORY_SEPARATOR;

        if (is_dir($this->dataDirectory) && is_dir($this->metadataDirectory)) {
            $this->assertWritableDirectory($this->dataDirectory, 'Cache data directory is not writable');
            $this->assertWritableDirectory($this->metadataDirectory, 'Cache metadata directory is not writable');

            return;
        }

        $this->ensureBaseDirectoryExists($baseDir);
        $this->ensureCacheDirectoryExists($this->dataDirectory);
        $this->ensureCacheDirectoryExists($this->metadataDirectory);
    }

    private function defaultBaseDirectory(): string
    {
        return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, self::DEFAULT_BASE_DIR);
    }

    private function ensureBaseDirectoryExists(string $baseDir): void
    {
        $this->assertPathNotSymlink($baseDir, 'Cache base directory');

        if (file_exists($baseDir) && !is_dir($baseDir)) {
            throw new RuntimeException(
                'Cache base path ' . realpath($baseDir) . ' exists and is *not* a directory',
            );
        }

        if (!is_dir($baseDir) && !mkdir($baseDir, 0700, true) && !is_dir($baseDir)) {
            $this->throwCreationError('Failed to create base directory ' . $baseDir);
        }

        $this->assertSecureDirectory($baseDir, 'Cache base directory');
    }

    private function ensureCacheDirectoryExists(string $cacheDir): void
    {
        $this->assertPathNotSymlink($cacheDir, 'Cache directory');

        if (file_exists($cacheDir) && !is_dir($cacheDir)) {
            throw new RuntimeException(
                realpath($cacheDir) . ' exists and is not a directory',
            );
        }

        if (!mkdir($cacheDir, 0700, true) && !is_dir($cacheDir)) {
            $this->throwCreationError('Failed to create cache directory ' . $cacheDir);
        }

        $this->assertSecureDirectory($cacheDir, 'Cache directory');
    }

    private function fileFor(string $key): string
    {
        return $this->dataDirectory . hash('xxh128', $key) . '.cache';
    }

    private function metadataFileFor(string $tag): string
    {
        return $this->metadataDirectory . hash('xxh128', $tag) . '.version';
    }

    private function persistItem(CacheItemInterface $item): bool
    {

        $expires = CachePayloadCodec::expirationFromItem($item);
        $ttl = $expires['ttl'];
        if ($ttl !== null && $ttl <= 0) {
            return $this->deleteItem($item->getKey());
        }

        $blob = $this->encodeItem($item, $expires['expiresAt']);
        $tmp = tempnam($this->dataDirectory, 'c_');
        if ($tmp === false) {
            return false;
        }

        if (file_put_contents($tmp, $blob, LOCK_EX) === false) {
            if (is_file($tmp)) {
                unlink($tmp);
            }

            return false;
        }

        if (!rename($tmp, $this->fileFor($item->getKey()))) {
            if (is_file($tmp)) {
                unlink($tmp);
            }

            return false;
        }

        return true;
    }

    private function throwCreationError(string $prefix): void
    {
        $err = error_get_last()['message'] ?? 'unknown error';

        throw new RuntimeException($prefix . ": $err");
    }
}
