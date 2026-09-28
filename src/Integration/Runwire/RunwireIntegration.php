<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Integration\Runwire;

use Fiber;
use Infocyph\CacheLayer\Memoize\Memoizer;
use Infocyph\CacheLayer\Memoize\OnceMemoizer;
use Infocyph\Runwire\Coroutine\CoroutineScope;
use Infocyph\Runwire\RequestContext;
use Infocyph\Runwire\Runtime\Enum\RuntimeCapability;
use Infocyph\Runwire\RuntimeContext;
use LogicException;
use WeakMap;

final class RunwireIntegration
{
    private const string MEMOIZER_ATTRIBUTE = 'cachelayer.memoizer';

    private const string ONCE_MEMOIZER_ATTRIBUTE = 'cachelayer.once-memoizer';

    /** @var WeakMap<Fiber<mixed, mixed, mixed, mixed>, RunwireExecutionContext>|null */
    private static ?WeakMap $fiberContexts = null;

    private static ?RunwireExecutionContext $rootContext = null;

    private static ?RuntimeContext $runtime = null;

    public static function bind(RuntimeContext $runtime): void
    {
        if (self::$runtime === $runtime) {
            return;
        }

        self::resetExecutionContexts();
        self::flushGlobalMemoizers();
        self::$runtime = $runtime;
    }

    public static function current(): ?RunwireExecutionContext
    {
        $fiber = Fiber::getCurrent();
        if ($fiber !== null) {
            $contexts = self::$fiberContexts;

            return $contexts !== null && isset($contexts[$fiber])
                ? $contexts[$fiber]
                : null;
        }

        return self::$rootContext;
    }

    public static function flushMemoizers(): void
    {
        $request = self::current()?->request;
        if ($request === null) {
            self::flushGlobalMemoizers();

            return;
        }

        $memoizer = $request->attribute(self::MEMOIZER_ATTRIBUTE);
        if ($memoizer instanceof Memoizer) {
            $memoizer->flush();
        }

        $once = $request->attribute(self::ONCE_MEMOIZER_ATTRIBUTE);
        if ($once instanceof OnceMemoizer) {
            $once->flush();
        }
    }

    public static function memoizer(): ?Memoizer
    {
        $request = self::current()?->request;
        if ($request !== null) {
            $memoizer = $request->attribute(self::MEMOIZER_ATTRIBUTE);
            if ($memoizer instanceof Memoizer) {
                return $memoizer;
            }

            $memoizer = Memoizer::isolated();
            $request->setAttribute(self::MEMOIZER_ATTRIBUTE, $memoizer);

            return $memoizer;
        }

        if (self::$runtime?->persistent === true && self::$runtime->concurrent) {
            return null;
        }

        return Memoizer::instance();
    }

    public static function onceMemoizer(): ?OnceMemoizer
    {
        $request = self::current()?->request;
        if ($request !== null) {
            $memoizer = $request->attribute(self::ONCE_MEMOIZER_ATTRIBUTE);
            if ($memoizer instanceof OnceMemoizer) {
                return $memoizer;
            }

            $memoizer = OnceMemoizer::isolated();
            $request->setAttribute(self::ONCE_MEMOIZER_ATTRIBUTE, $memoizer);

            return $memoizer;
        }

        if (self::$runtime?->persistent === true && self::$runtime->concurrent) {
            return null;
        }

        return OnceMemoizer::instance();
    }

    public static function release(?RuntimeContext $runtime = null): void
    {
        if ($runtime !== null && self::$runtime !== $runtime) {
            return;
        }

        self::resetExecutionContexts();
        self::flushGlobalMemoizers();
        self::$runtime = null;
    }

    public static function runtime(): ?RuntimeContext
    {
        return self::$runtime;
    }

    public static function share(
        ?RequestContext $request,
        ?CoroutineScope $scope,
        callable $callback,
    ): mixed {
        $runtime = self::$runtime;
        if ($runtime === null) {
            return $callback();
        }

        if ($request !== null && $request->runtime() !== $runtime) {
            throw new LogicException('Runwire request context is bound to a different runtime.');
        }

        $context = new RunwireExecutionContext($runtime, $request, $scope);
        $fiber = Fiber::getCurrent();
        if ($fiber === null) {
            $previous = self::$rootContext;
            self::$rootContext = $context;

            try {
                return $callback();
            } finally {
                self::$rootContext = $previous;
            }
        }

        self::$fiberContexts ??= new WeakMap();
        $hadPrevious = isset(self::$fiberContexts[$fiber]);
        $previous = $hadPrevious ? self::$fiberContexts[$fiber] : null;
        self::$fiberContexts[$fiber] = $context;

        try {
            return $callback();
        } finally {
            if ($hadPrevious && $previous instanceof RunwireExecutionContext) {
                self::$fiberContexts[$fiber] = $previous;
            } else {
                unset(self::$fiberContexts[$fiber]);
            }
        }
    }

    public static function sleep(float $seconds): void
    {
        if ($seconds <= 0.0) {
            return;
        }

        $context = self::current();
        if (
            $context?->scope !== null
            && $context->supports(RuntimeCapability::RUNWIRE_COROUTINES)
        ) {
            $context->scope->sleep($seconds);

            return;
        }

        usleep((int) min(PHP_INT_MAX, ceil($seconds * 1_000_000)));
    }

    public static function supports(RuntimeCapability $capability): bool
    {
        return self::$runtime?->supports($capability) ?? false;
    }

    private static function flushGlobalMemoizers(): void
    {
        Memoizer::instance()->flush();
        OnceMemoizer::instance()->flush();
    }

    private static function resetExecutionContexts(): void
    {
        self::$fiberContexts = null;
        self::$rootContext = null;
    }
}
