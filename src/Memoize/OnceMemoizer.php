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
        CallableFingerprint::flush();
    }

    public function once(callable $callback, int $callerOffset = 0): mixed
    {
        $key = $this->cacheKey($callback, $callerOffset);
        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        $value = $callback();
        $this->cache[$key] = $value;
        $this->trackCacheKey($key);

        return $value;
    }

    private function cacheKey(callable $callback, int $callerOffset): string
    {
        $bt = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 4 + $callerOffset);
        $location = $bt[1 + $callerOffset] ?? [];
        $caller = $bt[2 + $callerOffset] ?? $location;
        $callerObject = $caller['object'] ?? null;

        return ($location['file'] ?? '(unknown)')
            . ':' . ($location['line'] ?? 0)
            . ':' . ($caller['class'] ?? '')
            . ':' . $this->normalizeCallerFunction($caller['function'] ?? '(unknown)')
            . ':' . (is_object($callerObject) ? spl_object_id($callerObject) : '')
            . ':' . $this->callbackFingerprint($callback);
    }

    private function callbackFingerprint(callable $callback): string
    {
        if ($callback instanceof Closure) {
            $reflection = new ReflectionFunction($callback);
            $bound = $reflection->getClosureThis();

            return implode(':', [
                'closure',
                $reflection->getFileName() ?: 'internal',
                $reflection->getStartLine(),
                $reflection->getEndLine(),
                $reflection->getClosureScopeClass()?->getName() ?? '',
                $bound === null ? '' : $bound::class . '#' . spl_object_id($bound),
            ]);
        }

        return CallableFingerprint::callable($callback);
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
        $this->order[] = $key;
        if (count($this->order) <= self::LIMIT) {
            return;
        }

        $oldest = array_shift($this->order);
        unset($this->cache[$oldest]);
    }
}
