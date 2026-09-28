<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Integration\Runwire\RunwireIntegration;
use Infocyph\CacheLayer\Memoize\Memoizer;

if (!function_exists('memoize')) {
    /**
     * @param array<int, mixed> $params
     *
     * @throws ReflectionException
     */
    function memoize(?callable $callable = null, array $params = []): mixed
    {
        $memoizer = RunwireIntegration::memoizer();
        if ($callable === null) {
            return $memoizer ?? Memoizer::isolated();
        }
        if ($memoizer === null) {
            return $callable(...$params);
        }

        return $memoizer->get($callable, $params);
    }
}

if (!function_exists('remember')) {
    /**
     * @param array<int, mixed> $params
     *
     * @throws ReflectionException
     */
    function remember(?object $object = null, ?callable $callable = null, array $params = []): mixed
    {
        $memoizer = RunwireIntegration::memoizer();

        if ($object === null) {
            return $memoizer ?? Memoizer::isolated();
        }

        if ($callable === null) {
            throw new InvalidArgumentException('remember() requires both object and callable');
        }

        if ($memoizer === null) {
            return $callable(...$params);
        }

        return $memoizer->getFor($object, $callable, $params);
    }
}

if (!function_exists('once')) {
    function once(callable $callback): mixed
    {
        $memoizer = RunwireIntegration::onceMemoizer();

        return $memoizer === null
            ? $callback()
            : $memoizer->once($callback, 1);
    }
}

if (!function_exists('flush_memoizers')) {
    function flush_memoizers(): void
    {
        RunwireIntegration::flushMemoizers();
    }
}
