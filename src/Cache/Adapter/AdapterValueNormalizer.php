<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cache\Adapter;

final class AdapterValueNormalizer
{
    /**
     * @phpstan-return array<string, mixed>|null
 * @param mixed $value The value argument.
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
     * @phpstan-return array<string, mixed>|null
 * @param mixed $value The value argument.
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
     * @param array $value The value argument.
     * @phpstan-param array<mixed, mixed> $value
     * @phpstan-return array<string, mixed>
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
     * @phpstan-return array<string, mixed>|null
 * @param object $value The value argument.
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
