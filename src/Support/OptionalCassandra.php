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

    /** @param array<int, mixed> $arguments */
    public static function executionOptions(array $arguments): mixed
    {
        $class = self::nestedClass('ExecutionOptions');
        if (!class_exists($class)) {
            return ['arguments' => $arguments];
        }

        return new $class(['arguments' => $arguments]);
    }

    public static function connect(string $keyspace): object
    {
        $class = self::rootClass();
        if (!class_exists($class) || !is_callable([$class, 'cluster'])) {
            throw new RuntimeException('ext-cassandra is not available.');
        }

        $cluster = $class::cluster();
        $builder = $cluster->build();
        $session = $builder->connect($keyspace);
        if (!is_object($session)) {
            throw new RuntimeException('Unable to create a Cassandra session.');
        }

        return $session;
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
