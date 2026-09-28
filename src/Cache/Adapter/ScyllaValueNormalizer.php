<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cache\Adapter;

/** @internal */
final class ScyllaValueNormalizer
{
    public static function expiry(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_object($value) && is_callable([$value, 'toInt'])) {
            $intValue = $value->toInt();

            return is_int($intValue) ? $intValue : null;
        }

        if (is_float($value) || (is_string($value) && is_numeric($value))) {
            return (int) $value;
        }

        if (is_object($value) && is_callable([$value, '__toString'])) {
            $stringValue = (string) $value;

            return is_numeric($stringValue) ? (int) $stringValue : null;
        }

        return null;
    }

    public static function string(mixed $value): ?string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_object($value) && is_callable([$value, 'toBinaryString'])) {
            $stringValue = $value->toBinaryString();

            return is_string($stringValue) ? $stringValue : null;
        }

        if (is_object($value) && is_callable([$value, '__toString'])) {
            return (string) $value;
        }

        return null;
    }
}
