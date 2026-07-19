<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cache\Lock;

use Throwable;

trait GeneratesLockTokens
{
    protected static function digestLockKey(string $key): string
    {
        return hash('xxh128', $key);
    }

    protected static function generateToken(): ?string
    {
        try {
            return bin2hex(random_bytes(16));
        } catch (Throwable) {
            return null;
        }
    }
}
