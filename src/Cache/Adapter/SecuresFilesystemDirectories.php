<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cache\Adapter;

use Closure;
use FilesystemIterator;
use Infocyph\CacheLayer\Support\FilesystemTrust;
use RuntimeException;
use UnexpectedValueException;

trait SecuresFilesystemDirectories
{
    protected function assertPathNotSymlink(string $path, string $label): void
    {
        if (FilesystemTrust::containsSymlink($path)) {
            throw new RuntimeException($label . " must not contain symlinks: {$path}");
        }
    }

    protected function assertSecureDirectory(string $path, string $label): void
    {
        $this->assertPathNotSymlink($path, $label);

        if (!is_dir($path) || !is_writable($path)) {
            throw new RuntimeException($label . " must be a writable directory: {$path}");
        }

        if (DIRECTORY_SEPARATOR === '/') {
            $perms = fileperms($path);
            if ($perms !== false && (($perms & 0x0002) === 0x0002)) {
                throw new RuntimeException($label . " must not be world-writable: {$path}");
            }
        }
    }

    protected function atomicReplace(string $path, string $contents): bool
    {
        $this->assertPathNotSymlink($path . '.lock', 'Cache metadata lock file');
        $lock = fopen($path . '.lock', 'c');
        if (!is_resource($lock) || !flock($lock, LOCK_EX)) {
            if (is_resource($lock)) {
                fclose($lock);
            }

            return false;
        }

        $temporary = tempnam(dirname($path), 'cl_');
        $stored = $temporary !== false
            && file_put_contents($temporary, $contents, LOCK_EX) === strlen($contents)
            && rename($temporary, $path);
        if (!$stored && is_string($temporary) && is_file($temporary)) {
            unlink($temporary);
        }
        flock($lock, LOCK_UN);
        fclose($lock);

        return $stored;
    }

    /** @param (Closure(string): void)|null $beforeDelete */
    private function clearDirectoryFiles(string $directory, string $suffix, ?Closure $beforeDelete = null): bool
    {
        try {
            $files = new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS);
        } catch (UnexpectedValueException) {
            return false;
        }

        $ok = true;
        foreach ($files as $file) {
            if (!$file->isFile() || !str_ends_with($file->getFilename(), $suffix)) {
                continue;
            }

            $path = $file->getPathname();
            $beforeDelete?->__invoke($path);
            $ok = $this->deleteFile($path) && $ok;
        }

        return $ok;
    }

    protected function deleteFile(string $path): bool
    {
        if (!is_file($path)) {
            return true;
        }

        set_error_handler(static fn(): bool => true);

        try {
            return unlink($path);
        } finally {
            restore_error_handler();
        }
    }
}
