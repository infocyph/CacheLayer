<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cluster\Transport;

use Infocyph\CacheLayer\Cluster\Exception\ClusterTransportException;

/** @internal */
final class InvalidationTransportData
{
    /**
     * @param array $data The transport data.
     * @param string $key The required field name.
     * @param string $source The transport description.
     * @phpstan-param array<string, mixed> $data
     */
    public static function requiredString(array $data, string $key, string $source): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new ClusterTransportException("Invalid {$key} returned by {$source}.");
        }

        return $value;
    }

    /**
     * @param array $data The transport data.
     * @phpstan-param array<mixed, mixed> $data
     * @phpstan-return array<string, mixed>
     */
    public static function stringKeys(array $data): array
    {
        $normalized = [];
        foreach ($data as $key => $value) {
            if (is_string($key)) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }

    public static function unsignedInteger(mixed $value, string $field, string $source): int
    {
        if (is_int($value)) {
            if ($value >= 0) {
                return $value;
            }

            throw new ClusterTransportException("Invalid {$field} returned by {$source}.");
        }

        if (!is_string($value) || $value === '' || !ctype_digit($value)) {
            throw new ClusterTransportException("Invalid {$field} returned by {$source}.");
        }

        $normalized = ltrim($value, '0');
        if ($normalized === '') {
            return 0;
        }

        $maximum = (string) PHP_INT_MAX;
        if (strlen($normalized) > strlen($maximum)
            || (strlen($normalized) === strlen($maximum) && strcmp($normalized, $maximum) > 0)) {
            throw new ClusterTransportException("Invalid {$field} returned by {$source}.");
        }

        return (int) $normalized;
    }
}
