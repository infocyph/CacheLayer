<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cache\Metrics;

/** @internal */
final class CacheMetricsSnapshot
{
    /**
     * @param array<string, array<string, int>> $snapshot
     * @return array<string, array<string, int>>
     */
    public static function readable(array $snapshot): array
    {
        $readable = [];
        foreach ($snapshot as $adapterClass => $counters) {
            $separator = strrpos($adapterClass, '\\');
            $short = $separator === false ? $adapterClass : substr($adapterClass, $separator + 1);
            $short = preg_replace('/CacheAdapter$/', '', $short) ?? $short;
            $name = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $short) ?? $short);
            $readable[$name] = $counters;
        }

        return $readable;
    }
}
