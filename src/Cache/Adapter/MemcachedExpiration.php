<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cache\Adapter;

use Infocyph\CacheLayer\Exceptions\CacheInvalidArgumentException;

/** @internal */
final class MemcachedExpiration
{
    private const int MAX_RELATIVE_SECONDS = 2_592_000;

    public static function fromRelative(?int $seconds, ?int $now = null): int
    {
        if ($seconds === null || $seconds <= 0) {
            return 0;
        }
        if ($seconds <= self::MAX_RELATIVE_SECONDS) {
            return $seconds;
        }

        $now ??= time();
        if ($seconds > PHP_INT_MAX - $now) {
            throw new CacheInvalidArgumentException('Memcached expiration exceeds the supported timestamp range.');
        }

        return $now + $seconds;
    }
}
