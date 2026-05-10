<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cache\Adapter;

final class AdapterValueNormalizer
{
    /**
     * @return array<string, mixed>|null
     */
    public static function fromArrayLikeOrToArray(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            return self::normalizeAssoc($value);
        }

        if ($value instanceof \ArrayAccess && $value instanceof \Traversable) {
            $out = [];
            foreach ($value as $k => $v) {
                if (is_string($k)) {
                    $out[$k] = $v;
                }
            }

            return $out;
        }

        if (is_object($value) && method_exists($value, 'toArray')) {
            $arr = $value->toArray();

            return is_array($arr) ? self::normalizeAssoc($arr) : null;
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function fromJsonOrArrayLike(mixed $value): ?array
    {
        if ($value instanceof \JsonSerializable) {
            $json = $value->jsonSerialize();

            return is_array($json) ? self::normalizeAssoc($json) : null;
        }

        return self::fromArrayLikeOrToArray($value);
    }

    /**
     * @param array<mixed, mixed> $value
     * @return array<string, mixed>
     */
    public static function normalizeAssoc(array $value): array
    {
        $out = [];
        foreach ($value as $k => $v) {
            if (is_string($k)) {
                $out[$k] = $v;
            }
        }

        return $out;
    }
}
