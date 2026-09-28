<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Support;

final class FilesystemTrust
{
    public static function containsSymlink(string $path): bool
    {
        $cursor = rtrim($path, DIRECTORY_SEPARATOR);
        if ($cursor === '') {
            return false;
        }

        while (true) {
            if (is_link($cursor)) {
                return true;
            }
            $parent = dirname($cursor);
            if ($parent === $cursor || $parent === '.') {
                return false;
            }
            $cursor = $parent;
        }
    }
}
