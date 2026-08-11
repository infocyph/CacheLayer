<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Memoize;

use Closure;
use ReflectionFunction;
use ReflectionReference;
use WeakMap;

/** @internal */
final class CallableFingerprint
{
    /** @var WeakMap<Closure, string>|null */
    private static ?WeakMap $closures = null;

    private static int $nextObjectId = 0;

    /** @var WeakMap<object, string>|null */
    private static ?WeakMap $objects = null;

    public static function callable(callable $callable): string
    {
        if ($callable instanceof Closure) {
            return self::closure($callable);
        }
        if (is_string($callable)) {
            return 'string:' . $callable;
        }
        if (is_array($callable)) {
            $target = is_object($callable[0])
                ? self::object($callable[0])
                : $callable[0];

            return 'array:' . $target . '::' . $callable[1];
        }

        if (is_object($callable)) {
            return 'invokable:' . self::object($callable);
        }

        throw new \LogicException('Unsupported callable form.');
    }

    public static function flush(): void
    {
        self::$closures = new WeakMap();
        self::$objects = new WeakMap();
    }

    public static function value(mixed $value): mixed
    {
        return match (true) {
            $value instanceof Closure => self::closure($value),
            is_object($value) => self::object($value),
            is_resource($value) => 'res:' . get_resource_type($value) . '#' . (int) $value,
            is_array($value) => self::values($value),
            default => $value,
        };
    }

    private static function closure(Closure $closure): string
    {
        self::$closures ??= new WeakMap();
        if (isset(self::$closures[$closure])) {
            return self::$closures[$closure];
        }

        $reflection = new ReflectionFunction($closure);
        $statics = $reflection->getStaticVariables();
        $captures = [];
        foreach ($statics as $name => $value) {
            $reference = ReflectionReference::fromArrayElement($statics, $name);
            $captures[$name] = $reference instanceof ReflectionReference
                ? ['reference', bin2hex($reference->getId()), self::value($value)]
                : self::value($value);
        }
        $bound = $reflection->getClosureThis();
        $scope = $reflection->getClosureScopeClass();
        $identity = [
            $reflection->getFileName() ?: 'internal',
            $reflection->getStartLine(),
            $reflection->getEndLine(),
            $captures,
            $bound === null ? null : self::object($bound),
            $scope?->getName(),
        ];

        return self::$closures[$closure] = 'closure:' . hash('xxh128', serialize($identity));
    }

    private static function object(object $object): string
    {
        self::$objects ??= new WeakMap();

        return self::$objects[$object] ??= 'obj:' . $object::class . '#' . ++self::$nextObjectId;
    }

    /**
     * @param array<mixed> $values
     * @return array<mixed>
     */
    private static function values(array $values): array
    {
        $normalized = [];
        foreach ($values as $key => $value) {
            $normalized[$key] = self::value($value);
        }

        return $normalized;
    }
}
