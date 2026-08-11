<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Memoize;

use ReflectionException;
use WeakMap;

final class Memoizer
{
    use MemoizeTrait;

    private const int CACHE_LIMIT = 2048;

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
        CallableFingerprint::flush();
    }

    /**
     * @throws ReflectionException
     * @param callable $callable The callable argument.
     * @param array $params The params argument.
     * @phpstan-param array<int, mixed> $params
     */
    public function get(callable $callable, array $params = []): mixed
    {
        $cacheKey = self::buildCacheKey(
            CallableFingerprint::callable($callable),
            $params,
        );

        if (array_key_exists($cacheKey, $this->staticCache)) {
            $this->hits++;

            return $this->staticCache[$cacheKey];
        }

        $this->misses++;
        $value = $callable(...$params);
        self::evictOldestIfFull($this->staticCache);
        $this->staticCache[$cacheKey] = $value;

        return $value;
    }

    /**
     * @throws ReflectionException
     * @param object $object The object argument.
     * @param callable $callable The callable argument.
     * @param array $params The params argument.
     * @phpstan-param array<int, mixed> $params
     */
    public function getFor(object $object, callable $callable, array $params = []): mixed
    {
        $cacheKey = self::buildCacheKey(
            CallableFingerprint::callable($callable),
            $params,
        );

        $bucket = $this->objectCache[$object] ?? [];
        if (array_key_exists($cacheKey, $bucket)) {
            $this->hits++;

            return $bucket[$cacheKey];
        }

        $this->misses++;
        $value = $callable(...$params);
        self::evictOldestIfFull($bucket);
        $bucket[$cacheKey] = $value;
        $this->objectCache[$object] = $bucket;

        return $value;
    }

    /**
     * @phpstan-return array{hits:int,misses:int,total:int}
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
     * @param string $signature The signature argument.
     * @param array $params The params argument.
     * @phpstan-param array<int, mixed> $params
     */
    private static function buildCacheKey(string $signature, array $params): string
    {
        if ($params === []) {
            return $signature;
        }

        $normalized = [];
        foreach ($params as $param) {
            $normalized[] = CallableFingerprint::value($param);
        }

        return $signature . '|' . hash('xxh128', serialize($normalized));
    }

    /**
     * @param array $cache The cache bucket.
     * @phpstan-param array<string, mixed> $cache
     */
    private static function evictOldestIfFull(array &$cache): void
    {
        if (count($cache) < self::CACHE_LIMIT) {
            return;
        }

        $oldest = array_key_first($cache);
        unset($cache[$oldest]);
    }
}
