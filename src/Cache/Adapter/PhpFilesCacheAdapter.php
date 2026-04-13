<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cache\Adapter;

use Infocyph\CacheLayer\Cache\Item\GenericCacheItem;
use Psr\Cache\CacheItemInterface;
use RuntimeException;

final class PhpFilesCacheAdapter extends AbstractCacheAdapter
{
    private const string DEFAULT_BASE_DIR = 'cachelayer/phpfiles';
    private string $dir;

    public function __construct(string $namespace = 'default', ?string $baseDir = null)
    {
        $this->createDirectory($namespace, $baseDir);
    }

    public function clear(): bool
    {
        $ok = true;
        foreach (glob($this->dir . '*.php') ?: [] as $file) {
            $ok = (@unlink($file) || !is_file($file)) && $ok;
            $this->invalidateOpcache($file);
        }

        $this->deferred = [];
        return $ok;
    }

    public function count(): int
    {
        $count = 0;
        foreach (glob($this->dir . '*.php') ?: [] as $file) {
            $row = @require $file;
            if (!is_array($row) || !isset($row['p']) || !is_string($row['p'])) {
                continue;
            }

            $blob = base64_decode($row['p'], true);
            if (!is_string($blob)) {
                continue;
            }

            $record = CachePayloadCodec::decode($blob);
            if ($record !== null && !CachePayloadCodec::isExpired($record['expires'])) {
                $count++;
            }
        }

        return $count;
    }

    public function deleteItem(string $key): bool
    {
        $file = $this->fileFor($key);
        $ok = !is_file($file) || @unlink($file);
        $this->invalidateOpcache($file);
        return $ok;
    }

    public function deleteItems(array $keys): bool
    {
        $ok = true;
        foreach ($keys as $key) {
            $ok = $this->deleteItem((string) $key) && $ok;
        }

        return $ok;
    }

    public function getItem(string $key): GenericCacheItem
    {
        $file = $this->fileFor($key);
        if (!is_file($file)) {
            return new GenericCacheItem($this, $key);
        }

        $row = @require $file;
        if (!is_array($row) || !isset($row['p']) || !is_string($row['p'])) {
            $this->deleteItem($key);
            return new GenericCacheItem($this, $key);
        }

        $blob = base64_decode($row['p'], true);
        if (!is_string($blob)) {
            $this->deleteItem($key);
            return new GenericCacheItem($this, $key);
        }

        $record = CachePayloadCodec::decode($blob);
        if ($record === null || CachePayloadCodec::isExpired($record['expires'])) {
            $this->deleteItem($key);
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

        $blob = CachePayloadCodec::encode($item->get(), $expires['expiresAt']);
        $payload = var_export(base64_encode($blob), true);
        $code = "<?php\n\nreturn ['p' => {$payload}];\n";

        $file = $this->fileFor($item->getKey());
        $tmp = tempnam($this->dir, 'pc_');
        if ($tmp === false) {
            return false;
        }

        if (file_put_contents($tmp, $code) === false) {
            @unlink($tmp);
            return false;
        }

        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            return false;
        }

        $this->invalidateOpcache($file);
        return true;
    }

    public function setNamespaceAndDirectory(string $namespace, ?string $baseDir = null): void
    {
        $this->createDirectory($namespace, $baseDir);
        $this->deferred = [];
    }

    protected function supportsItem(CacheItemInterface $item): bool
    {
        return $item instanceof GenericCacheItem;
    }

    private function assertPathNotSymlink(string $path, string $label): void
    {
        if (is_link($path)) {
            throw new RuntimeException($label . " must not be a symlink: {$path}");
        }
    }

    private function assertSecureDirectory(string $path, string $label): void
    {
        $this->assertPathNotSymlink($path, $label);

        $perms = fileperms($path);
        if ($perms !== false && (($perms & 0x0002) === 0x0002)) {
            throw new RuntimeException($label . " must not be world-writable: {$path}");
        }
    }

    private function createDirectory(string $ns, ?string $baseDir): void
    {
        $baseDir = rtrim($baseDir ?? $this->defaultBaseDirectory(), DIRECTORY_SEPARATOR);
        $ns = sanitize_cache_ns($ns);
        $this->dir = $baseDir . DIRECTORY_SEPARATOR . 'phpcache_' . $ns . DIRECTORY_SEPARATOR;

        $this->assertPathNotSymlink($baseDir, 'PHP cache base directory');
        $this->assertPathNotSymlink($this->dir, 'PHP cache directory');

        if (!is_dir($baseDir) && !@mkdir($baseDir, 0700, true) && !is_dir($baseDir)) {
            throw new RuntimeException("Unable to create PHP cache base directory: {$baseDir}");
        }

        if (!is_dir($this->dir) && !@mkdir($this->dir, 0700, true) && !is_dir($this->dir)) {
            throw new RuntimeException("Unable to create PHP cache directory: {$this->dir}");
        }

        $this->assertSecureDirectory($baseDir, 'PHP cache base directory');
        if (!is_writable($this->dir)) {
            throw new RuntimeException("PHP cache directory is not writable: {$this->dir}");
        }

        $this->assertSecureDirectory($this->dir, 'PHP cache directory');
    }

    private function defaultBaseDirectory(): string
    {
        return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, self::DEFAULT_BASE_DIR);
    }

    private function fileFor(string $key): string
    {
        return $this->dir . hash('xxh128', $key) . '.php';
    }

    private function invalidateOpcache(string $file): void
    {
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($file, true);
        }
    }
}
