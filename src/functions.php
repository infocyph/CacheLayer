<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Memoize\Memoizer;
use Infocyph\CacheLayer\Memoize\OnceMemoizer;

if (! function_exists('sanitize_cache_ns')) {
    /**
     * Normalize cache namespaces into safe key prefixes.
     */
    function sanitize_cache_ns(string $ns): string
    {
        static $cache = [];

        return $cache[$ns] ??= (preg_replace('/[^A-Za-z0-9_\-]/', '_', $ns) ?? '');
    }
}

if (! function_exists('memoize')) {
    /**
     * @throws ReflectionException
     */
    function memoize(?callable $callable = null, array $params = []): mixed
    {
        $memoizer = Memoizer::instance();
        if ($callable === null) {
            return $memoizer;
        }

        return $memoizer->get($callable, $params);
    }
}

if (! function_exists('remember')) {
    /**
     * @throws ReflectionException
     */
    function remember(?object $object = null, ?callable $callable = null, array $params = []): mixed
    {
        $memoizer = Memoizer::instance();

        if ($object === null) {
            return $memoizer;
        }

        if ($callable === null) {
            throw new InvalidArgumentException('remember() requires both object and callable');
        }

        return $memoizer->getFor($object, $callable, $params);
    }
}

if (! function_exists('once')) {
    function once(callable $callback): mixed
    {
        return OnceMemoizer::instance()->once($callback);
    }
}
