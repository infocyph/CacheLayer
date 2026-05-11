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
        return match (true) {
            $value === null => null,
            is_array($value) => self::normalizeAssoc($value),
            $value instanceof \ArrayAccess && $value instanceof \Traversable => self::normalizeAssoc(iterator_to_array($value)),
            is_object($value) => self::normalizeFromToArray($value),
            default => null,
        };
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

    /**
     * @return array<string, mixed>|null
     */
    private static function normalizeFromToArray(object $value): ?array
    {
        $toArray = [$value, 'toArray'];
        if (!is_callable($toArray)) {
            return null;
        }

        $arrayValue = $toArray();

        return is_array($arrayValue) ? self::normalizeAssoc($arrayValue) : null;
    }
}
