<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cache\Adapter;

use RuntimeException;

trait SecuresFilesystemDirectories
{
    protected function assertPathNotSymlink(string $path, string $label): void
    {
        if (is_link($path)) {
            throw new RuntimeException($label . " must not be a symlink: {$path}");
        }
    }

    protected function assertSecureDirectory(string $path, string $label): void
    {
        $this->assertPathNotSymlink($path, $label);

        $perms = fileperms($path);
        if ($perms !== false && (($perms & 0x0002) === 0x0002)) {
            throw new RuntimeException($label . " must not be world-writable: {$path}");
        }
    }
}
