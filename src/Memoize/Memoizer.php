<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Memoize;

use Closure;
use ReflectionException;
use ReflectionFunction;
use WeakMap;

final class Memoizer
{
    use MemoizeTrait;

    private static ?self $instance = null;

    private int $hits = 0;

    private int $misses = 0;

    /** @var WeakMap<object, array<string, mixed>> */
    private WeakMap $objectCache;

    /** @var array<string, mixed> */
    private array $staticCache = [];

    private function __construct()
    {
        $this->objectCache = new WeakMap();
    }

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public function flush(): void
    {
        $this->staticCache = [];
        $this->objectCache = new WeakMap();
        $this->hits = $this->misses = 0;
    }

    /**
     * @param array<int, mixed> $params
     *
     * @throws ReflectionException
     */
    public function get(callable $callable, array $params = []): mixed
    {
        $cacheKey = self::buildCacheKey(
            self::callableSignature($callable),
            $params,
        );

        if (array_key_exists($cacheKey, $this->staticCache)) {
            $this->hits++;

            return $this->staticCache[$cacheKey];
        }

        $this->misses++;
        $value = $callable(...$params);
        $this->staticCache[$cacheKey] = $value;

        return $value;
    }

    /**
     * @param array<int, mixed> $params
     *
     * @throws ReflectionException
     */
    public function getFor(object $object, callable $callable, array $params = []): mixed
    {
        $cacheKey = self::buildCacheKey(
            self::callableSignature($callable),
            $params,
        );

        $bucket = $this->objectCache[$object] ?? [];
        if (array_key_exists($cacheKey, $bucket)) {
            $this->hits++;

            return $bucket[$cacheKey];
        }

        $this->misses++;
        $value = $callable(...$params);
        $bucket[$cacheKey] = $value;
        $this->objectCache[$object] = $bucket;

        return $value;
    }

    /**
     * @return array{hits:int,misses:int,total:int}
     */
    public function stats(): array
    {
        return [
            'hits' => $this->hits,
            'misses' => $this->misses,
            'total' => $this->hits + $this->misses,
        ];
    }

    /**
     * @param array<int, mixed> $params
     */
    private static function buildCacheKey(string $signature, array $params): string
    {
        if ($params === []) {
            return $signature;
        }

        $normalized = array_map(self::normalizeParam(...), $params);

        return $signature . '|' . hash('xxh3', serialize($normalized));
    }

    /**
     * @throws ReflectionException
     */
    private static function callableSignature(callable $callable): string
    {
        if ($callable instanceof Closure) {
            $rf = new ReflectionFunction($callable);
            $file = $rf->getFileName() ?: 'internal';

            return 'closure:' . $file . ':' . $rf->getStartLine() . '-' . $rf->getEndLine();
        }

        if (is_string($callable)) {
            return 'string:' . $callable;
        }

        if (is_array($callable)) {
            $target = is_object($callable[0]) ? $callable[0]::class : $callable[0];

            return 'array:' . $target . '::' . $callable[1];
        }

        if (is_object($callable)) {
            return 'invokable:' . $callable::class;
        }

        $rf = new ReflectionFunction(Closure::fromCallable($callable));
        $file = $rf->getFileName() ?: 'internal';

        return 'callable:' . $file . ':' . $rf->getStartLine() . '-' . $rf->getEndLine();
    }

    private static function normalizeParam(mixed $value): mixed
    {
        return match (true) {
            $value instanceof Closure => 'closure#' . spl_object_id($value),
            is_object($value) => 'obj#' . spl_object_id($value),
            is_resource($value) => 'res#' . get_resource_type($value) . '#' . (int) $value,
            is_array($value) => array_map(self::normalizeParam(...), $value),
            default => $value,
        };
    }
}
