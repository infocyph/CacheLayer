<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cache\Adapter;

use Infocyph\CacheLayer\Cache\Item\CacheItem;
use Psr\Cache\CacheItemInterface;
use RuntimeException;

final class PhpFilesCacheAdapter extends AbstractCacheAdapter
{
    use SecuresFilesystemDirectories;

    private const string DEFAULT_BASE_DIR = 'cachelayer/phpfiles';

    private string $dataDirectory;

    private string $metadataDirectory;

    public function __construct(string $namespace = 'default', ?string $baseDir = null)
    {
        $this->createDirectory($namespace, $baseDir);
    }

    public function clear(): bool
    {
        $ok = true;
        foreach (glob($this->dataDirectory . '*.php') ?: [] as $file) {
            $ok = (!is_file($file) || unlink($file)) && $ok;
            $this->invalidateOpcache($file);
        }
        foreach (glob($this->metadataDirectory . '*') ?: [] as $file) {
            $ok = (!is_file($file) || unlink($file)) && $ok;
        }

        $this->deferred = [];

        return $ok;
    }

    public function count(): int
    {
        $count = 0;
        foreach (glob($this->dataDirectory . '*.php') ?: [] as $file) {
            $row = require $file;
            if (!is_array($row) || !isset($row['p']) || !is_string($row['p'])) {
                continue;
            }

            $blob = base64_decode($row['p'], true);
            if (!is_string($blob)) {
                continue;
            }

            $record = $this->decodeRecordFromBlob($blob);
            if ($record !== null) {
                $count++;
            }
        }

        return $count;
    }

    public function deleteItem(string $key): bool
    {
        $file = $this->fileFor($key);
        $ok = !is_file($file) || unlink($file);
        $this->invalidateOpcache($file);

        return $ok;
    }

    /**
     * @param array $keys The keys argument.
     * @phpstan-param list<string> $keys
     */
    public function deleteItems(array $keys): bool
    {
        $ok = true;
        foreach ($keys as $key) {
            $ok = $this->deleteItem((string) $key) && $ok;
        }

        return $ok;
    }

    public function getItem(string $key): CacheItem
    {
        $file = $this->fileFor($key);
        if (!is_file($file)) {
            return $this->genericMiss($key);
        }

        $row = require $file;
        $payload = is_array($row) && is_string($row['p'] ?? null)
            ? $row['p']
            : null;
        if (!is_string($payload)) {
            return $this->genericDeleteAndMiss($key);
        }

        return $this->genericFromBase64WithInvalidator(
            $key,
            $payload,
            fn(): bool => $this->deleteItem($key),
        );
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
            $handle = fopen($this->metadataFileFor($tag), 'c+');
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
     * @param array $keys The keys argument.
     * @phpstan-param list<string> $keys
     * @phpstan-return array<string, CacheItem>
     */
    public function multiFetch(array $keys): array
    {
        $items = [];
        $stale = [];
        foreach ($keys as $key) {
            $file = $this->fileFor($key);
            if (!is_file($file)) {
                $items[$key] = $this->genericMiss($key);

                continue;
            }
            $row = require $file;
            $payload = is_array($row) && is_string($row['p'] ?? null) ? $row['p'] : null;
            $item = $this->genericFromBase64WithInvalidator($key, $payload, static fn(): bool => true);
            if (!$item->isHit()) {
                $stale[] = $key;
            }
            $items[$key] = $item;
        }
        if ($stale !== []) {
            $this->deleteItems($stale);
        }

        return $items;
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
        $ns = sanitize_cache_ns($ns);
        $root = $baseDir . DIRECTORY_SEPARATOR . 'cache_' . $ns . DIRECTORY_SEPARATOR;
        $this->dataDirectory = $root . 'data' . DIRECTORY_SEPARATOR;
        $this->metadataDirectory = $root . 'meta' . DIRECTORY_SEPARATOR;

        $this->assertPathNotSymlink($baseDir, 'PHP cache base directory');
        $this->assertPathNotSymlink($this->dataDirectory, 'PHP cache data directory');
        $this->assertPathNotSymlink($this->metadataDirectory, 'PHP cache metadata directory');

        if (!is_dir($baseDir) && !mkdir($baseDir, 0700, true) && !is_dir($baseDir)) {
            throw new RuntimeException("Unable to create PHP cache base directory: {$baseDir}");
        }

        if (!is_dir($this->dataDirectory)
            && !mkdir($this->dataDirectory, 0700, true)
            && !is_dir($this->dataDirectory)) {
            throw new RuntimeException("Unable to create PHP cache data directory: {$this->dataDirectory}");
        }
        if (!is_dir($this->metadataDirectory)
            && !mkdir($this->metadataDirectory, 0700, true)
            && !is_dir($this->metadataDirectory)) {
            throw new RuntimeException("Unable to create PHP cache metadata directory: {$this->metadataDirectory}");
        }

        $this->assertSecureDirectory($baseDir, 'PHP cache base directory');
        if (!is_writable($this->dataDirectory) || !is_writable($this->metadataDirectory)) {
            throw new RuntimeException('PHP cache directories are not writable.');
        }

        $this->assertSecureDirectory($this->dataDirectory, 'PHP cache data directory');
        $this->assertSecureDirectory($this->metadataDirectory, 'PHP cache metadata directory');
    }

    private function defaultBaseDirectory(): string
    {
        return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, self::DEFAULT_BASE_DIR);
    }

    private function fileFor(string $key): string
    {
        return $this->dataDirectory . hash('xxh128', $key) . '.php';
    }

    private function invalidateOpcache(string $file): void
    {
        if (function_exists('opcache_invalidate')) {
            if (is_file($file)) {
                opcache_invalidate($file, true);
            }
        }
    }

    private function metadataFileFor(string $tag): string
    {
        return $this->metadataDirectory . hash('xxh128', $tag) . '.version';
    }

    private function persistItem(CacheItemInterface $item): bool
    {

        $expires = CachePayloadCodec::expirationFromItem($item);
        if ($expires['ttl'] !== null && $expires['ttl'] <= 0) {
            return $this->deleteItem($item->getKey());
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

        if (!rename($tmp, $file)) {
            if (is_file($tmp)) {
                unlink($tmp);
            }

            return false;
        }

        $this->invalidateOpcache($file);

        return true;
    }
}
