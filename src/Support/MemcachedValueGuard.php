<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Support;

/** @internal */
final class MemcachedValueGuard
{
    /** Preserve the native CAS token representation, including unsigned decimal strings. */
    public static function casToken(mixed $token): string|int|float|null
    {
        if (is_int($token) || is_float($token)) {
            return $token;
        }

        return is_string($token) && preg_match('/^[0-9]{1,20}$/D', $token) === 1 ? $token : null;
    }

    public static function replaceIfUnchanged(
        \Memcached $client,
        string $key,
        string $observed,
        string $replacement,
        int $expiration = 0,
    ): string|false {
        $entry = $client->get($key, null, \Memcached::GET_EXTENDED);
        if (!is_array($entry) || !is_string($entry['value'] ?? null)) {
            return false;
        }

        $current = $entry['value'];
        if ($current !== $observed) {
            return $current;
        }

        $cas = self::casToken($entry['cas'] ?? null);
        if ($cas === null || !$client->cas($cas, $key, $replacement, $expiration)) {
            $latest = $client->get($key);

            return is_string($latest) ? $latest : false;
        }

        return $replacement;
    }
}
