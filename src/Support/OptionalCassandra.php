<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Support;

use RuntimeException;

final class OptionalCassandra
{
    public static function available(): bool
    {
        return class_exists(self::rootClass());
    }

    public static function bigint(?int $value): mixed
    {
        if ($value === null) {
            return null;
        }

        $class = self::nestedClass('Bigint');

        return class_exists($class) ? new $class($value) : $value;
    }

    public static function blob(string $value): mixed
    {
        $class = self::nestedClass('Blob');

        return class_exists($class) ? new $class($value) : $value;
    }

    /** @throws RuntimeException */
    public static function connect(string $keyspace): object
    {
        $class = self::rootClass();
        if (!class_exists($class) || !is_callable([$class, 'cluster'])) {
            throw new RuntimeException('ext-cassandra is not available.');
        }

        $cluster = $class::cluster();
        if (!is_object($cluster) || !is_callable([$cluster, 'build'])) {
            throw new RuntimeException('Unable to create a Cassandra cluster builder.');
        }

        $builder = $cluster->build();
        if (!is_object($builder) || !is_callable([$builder, 'connect'])) {
            throw new RuntimeException('Unable to create a Cassandra session builder.');
        }

        $session = $builder->connect($keyspace);
        if (!is_object($session)) {
            throw new RuntimeException('Unable to create a Cassandra session.');
        }

        return $session;
    }

    /**
     * @param array<int, mixed> $arguments
     * @return array{arguments:array<int, mixed>,consistency?:int,serial_consistency?:int}
     */
    public static function executionOptions(array $arguments, bool $serialRead = false, bool $lwt = false): array
    {
        $options = ['arguments' => $arguments];
        if ($serialRead) {
            $options['consistency'] = 8; // Native Cassandra SERIAL consistency.
        } elseif ($lwt) {
            $options['consistency'] = 4; // QUORUM commit.
            $options['serial_consistency'] = 8;
        }

        return $options;
    }

    public static function simpleStatement(string $cql): mixed
    {
        $class = self::nestedClass('SimpleStatement');

        return class_exists($class) ? new $class($cql) : $cql;
    }

    private static function nestedClass(string $name): string
    {
        return self::rootClass() . '\\' . $name;
    }

    private static function rootClass(): string
    {
        return implode('', ['Cassa', 'ndra']);
    }
}
