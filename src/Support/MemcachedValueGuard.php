<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Support;

/** @internal */
final class MemcachedValueGuard
{
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

        $cas = $entry['cas'] ?? null;
        if ((!is_int($cas) && !is_float($cas))
            || !$client->cas((float) $cas, $key, $replacement, $expiration)) {
            $latest = $client->get($key);

            return is_string($latest) ? $latest : false;
        }

        return $replacement;
    }
}
