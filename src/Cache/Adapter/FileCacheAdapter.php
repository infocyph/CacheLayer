<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cache\Adapter;

use Infocyph\CacheLayer\Cache\Item\FileCacheItem;
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

    private string $dir;

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
        $files = glob("$this->dir*.cache");
        if ($files === false) {
            $files = [];
        }
        foreach ($files as $f) {
            $ok = (!is_file($f) || unlink($f)) && $ok;
        }
        $this->deferred = [];

        return $ok;
    }

    public function count(): int
    {
        return iterator_count(new \FilesystemIterator($this->dir, \FilesystemIterator::SKIP_DOTS));
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

    public function getItem(string $key): FileCacheItem
    {
        $file = $this->fileFor($key);

        if (is_file($file)) {
            $raw = file_get_contents($file);
            if (is_string($raw)) {
                $record = CachePayloadCodec::decode($raw);
                if ($record !== null && !CachePayloadCodec::isExpired($record['expires'])) {
                    return new FileCacheItem(
                        $this,
                        $key,
                        $record['value'],
                        true,
                        CachePayloadCodec::toDateTime($record['expires']),
                    );
                }
            }
            unlink($file);
        }

        return new FileCacheItem($this, $key);
    }

    public function hasItem(string $key): bool
    {
        return $this->getItem($key)->isHit();
    }

    public function save(CacheItemInterface $item): bool
    {
        if (!$this->supportsItem($item)) {
            throw new CacheInvalidArgumentException('Invalid item type for FileCacheAdapter');
        }

        $expires = CachePayloadCodec::expirationFromItem($item);
        $ttl = $expires['ttl'];
        if ($ttl === 0) {
            return $this->deleteItem($item->getKey());
        }

        $blob = CachePayloadCodec::encode($item->get(), $expires['expiresAt']);
        $tmp = tempnam($this->dir, 'c_');
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

    public function setNamespaceAndDirectory(string $namespace, ?string $baseDir = null): void
    {
        $this->createDirectory($namespace, $baseDir);
        $this->deferred = [];
    }

    protected function supportsItem(CacheItemInterface $item): bool
    {
        return $item instanceof FileCacheItem;
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
        $this->dir = $baseDir . DIRECTORY_SEPARATOR . 'cache_' . $ns . DIRECTORY_SEPARATOR;

        if (is_dir($this->dir)) {
            $this->assertWritableDirectory($this->dir, "Cache directory '$this->dir' exists but is not writable");
            $this->assertSecureDirectory($this->dir, 'Cache directory');

            return;
        }

        $this->ensureBaseDirectoryExists($baseDir);
        $this->ensureCacheDirectoryExists($this->dir);
        $this->assertWritableDirectory($this->dir, 'Cache directory ' . $this->dir . ' is not writable');
        $this->assertSecureDirectory($this->dir, 'Cache directory');
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
        return $this->dir . hash('sha256', $key) . '.cache';
    }

    private function throwCreationError(string $prefix): void
    {
        $err = error_get_last()['message'] ?? 'unknown error';

        throw new RuntimeException($prefix . ": $err");
    }
}
