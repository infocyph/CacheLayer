<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Memoize;

use Closure;
use ReflectionFunction;

final class OnceMemoizer
{
    private const int LIMIT = 2048;

    private static ?self $instance = null;

    /** @var array<string, mixed> */
    private array $cache = [];

    /** @var array<string, string> */
    private array $closureSourceMemo = [];

    /** @var list<string> */
    private array $order = [];

    private function __construct() {}

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public function flush(): void
    {
        $this->cache = [];
        $this->order = [];
        $this->closureSourceMemo = [];
    }

    public function once(callable $callback): mixed
    {
        $key = $this->cacheKey($callback);
        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        $value = $callback();
        $this->cache[$key] = $value;
        $this->trackCacheKey($key);

        return $value;
    }

    private function cacheKey(callable $callback): string
    {
        $bt = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);
        $caller = $bt[2] ?? $bt[1] ?? [];

        return ($caller['file'] ?? '(unknown)')
            . ':' . ($caller['class'] ?? '')
            . ':' . $this->normalizeCallerFunction($caller['function'] ?? '(unknown)')
            . ':' . $this->callbackFingerprint($callback);
    }

    private function callbackFingerprint(callable $callback): string
    {
        return match (true) {
            $callback instanceof Closure => $this->closureFingerprint($callback),
            is_string($callback) => 'string:' . $callback,
            is_array($callback) => 'array:' . (is_object($callback[0]) ? $callback[0]::class : $callback[0]) . '::' . $callback[1],
            is_object($callback) => 'invokable:' . $callback::class,
            default => 'callable:' . get_debug_type($callback),
        };
    }

    private function closureFingerprint(Closure $closure): string
    {
        $rf = new ReflectionFunction($closure);
        $file = $rf->getFileName();
        $start = $rf->getStartLine();
        $end = $rf->getEndLine();
        $lineFingerprint = 'closure-lines:' . ($file ?: 'internal') . ':' . $start . '-' . $end;

        if (!is_string($file) || $file === '') {
            return $lineFingerprint;
        }
        if (!is_readable($file)) {
            return $lineFingerprint;
        }

        $sourceKey = $file . ':' . $start . '-' . $end;
        $cached = $this->closureSourceMemo[$sourceKey] ?? null;
        if (is_string($cached)) {
            return $cached;
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES);
        if (!is_array($lines)) {
            return $lineFingerprint;
        }

        $snippet = implode("\n", array_slice($lines, $start - 1, $end - $start + 1));
        $normalized = preg_replace('/\s+/', '', $snippet) ?? $snippet;
        $fingerprint = 'closure-src:' . hash('xxh3', $normalized);
        $this->closureSourceMemo[$sourceKey] = $fingerprint;

        return $fingerprint;
    }

    private function normalizeCallerFunction(string $callerFunction): string
    {
        if (str_starts_with($callerFunction, '{closure:')) {
            return '{closure}';
        }

        return $callerFunction;
    }

    private function trackCacheKey(string $key): void
    {
        if (in_array($key, $this->order, true)) {
            return;
        }

        $this->order[] = $key;
        if (count($this->order) <= self::LIMIT) {
            return;
        }

        $oldest = array_shift($this->order);
        unset($this->cache[$oldest]);
    }
}
