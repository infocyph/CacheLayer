<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Memoize;

use Closure;
use Infocyph\CacheLayer\Support\BoundedValueTraversal;
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
                ? self::objectIdentity($callable[0])
                : 'class:' . $callable[0];

            return 'array:' . $target . '::' . $callable[1];
        }

        if (is_object($callable)) {
            return 'invokable:' . self::objectIdentity($callable);
        }

        throw new \LogicException('Unsupported callable form.');
    }

    public static function flush(): void
    {
        self::$closures = new WeakMap();
        self::$objects = new WeakMap();
    }

    public static function objectIdentity(object $object): string
    {
        self::$objects ??= new WeakMap();

        return self::$objects[$object] ??= $object::class . '#' . ++self::$nextObjectId;
    }

    public static function value(mixed $value): mixed
    {
        BoundedValueTraversal::assertSafe($value);

        return self::normalizeValue($value);
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
            $captures[] = [
                'name' => $name,
                'reference' => $reference instanceof ReflectionReference ? bin2hex($reference->getId()) : null,
                'value' => self::normalizeValue($value),
            ];
        }
        $bound = $reflection->getClosureThis();
        $scope = $reflection->getClosureScopeClass();
        $identity = [
            'instance' => self::objectIdentity($closure),
            'file' => $reflection->getFileName() ?: 'internal',
            'start' => $reflection->getStartLine(),
            'end' => $reflection->getEndLine(),
            'captures' => $captures,
            'bound' => $bound === null ? null : self::objectIdentity($bound),
            'scope' => $scope?->getName(),
        ];

        return self::$closures[$closure] = 'closure:' . hash('xxh128', serialize($identity));
    }

    private static function normalizeValue(mixed $value): mixed
    {
        return match (true) {
            $value === null => ['null'],
            is_bool($value) => ['bool', $value],
            is_int($value) => ['int', $value],
            is_float($value) => ['float', serialize($value)],
            is_string($value) => ['string', $value],
            $value instanceof Closure => ['closure', self::closure($value)],
            is_object($value) => ['object', self::objectIdentity($value)],
            is_resource($value) => ['resource', get_resource_type($value), (int) $value],
            is_array($value) => ['array', self::values($value)],
            default => ['type', get_debug_type($value)],
        };
    }

    /**
     * @param array<mixed> $values
     * @return list<array{key:array{0:string,1:int|string},value:mixed}>
     */
    private static function values(array $values): array
    {
        $normalized = [];
        foreach ($values as $key => $value) {
            $normalized[] = [
                'key' => [is_int($key) ? 'int' : 'string', $key],
                'value' => self::normalizeValue($value),
            ];
        }

        return $normalized;
    }
}
