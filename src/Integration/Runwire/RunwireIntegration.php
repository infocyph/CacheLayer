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
use WeakMap;

final class RunwireIntegration
{
    private const string MEMOIZER_ATTRIBUTE = 'cachelayer.memoizer';

    private const string ONCE_MEMOIZER_ATTRIBUTE = 'cachelayer.once-memoizer';

    /** @var WeakMap<object, RunwireExecutionContext>|null */
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

    public static function checkpoint(): void
    {
        $context = self::current();
        $context?->request?->cancellation->throwIfCancelled();
        if (
            $context?->scope !== null
            && $context->supports(RuntimeCapability::RUNWIRE_COROUTINES)
        ) {
            $context->scope->cancellation()->throwIfCancelled();
        }
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
        $context = self::current();
        $request = $context?->request;
        if ($request !== null) {
            $memoizer = $request->attribute(self::MEMOIZER_ATTRIBUTE);
            if ($memoizer instanceof Memoizer) {
                return $memoizer;
            }

            $memoizer = Memoizer::isolated();
            $request->setAttribute(self::MEMOIZER_ATTRIBUTE, $memoizer);

            return $memoizer;
        }

        $runtime = $context->runtime ?? self::$runtime;
        if ($runtime?->persistent === true && $runtime->concurrent) {
            return null;
        }

        return Memoizer::instance();
    }

    public static function onceMemoizer(): ?OnceMemoizer
    {
        $context = self::current();
        $request = $context?->request;
        if ($request !== null) {
            $memoizer = $request->attribute(self::ONCE_MEMOIZER_ATTRIBUTE);
            if ($memoizer instanceof OnceMemoizer) {
                return $memoizer;
            }

            $memoizer = OnceMemoizer::isolated();
            $request->setAttribute(self::ONCE_MEMOIZER_ATTRIBUTE, $memoizer);

            return $memoizer;
        }

        $runtime = $context->runtime ?? self::$runtime;
        if ($runtime?->persistent === true && $runtime->concurrent) {
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
        return self::current()->runtime ?? self::$runtime;
    }

    /** Borrow an explicit host runtime for this callback without global lifecycle ownership. */
    public static function share(
        ?RequestContext $request,
        ?CoroutineScope $scope,
        callable $callback,
        ?RuntimeContext $runtime = null,
    ): mixed {
        $runtime ??= self::runtime() ?? $request?->runtime();
        if ($runtime === null) {
            return $callback();
        }

        $context = new RunwireExecutionContext($runtime, $request, $scope);
        $fiber = Fiber::getCurrent();
        if ($fiber === null) {
            $previous = self::$rootContext;
            self::$rootContext = $context;

            try {
                return $callback();
            } finally {
                if (self::$rootContext === $context) {
                    self::$rootContext = $previous;
                }
            }
        }

        $contexts = self::$fiberContexts ??= new WeakMap();
        $previous = $contexts[$fiber] ?? null;
        $contexts[$fiber] = $context;

        try {
            return $callback();
        } finally {
            if (self::$fiberContexts === $contexts) {
                if ($previous instanceof RunwireExecutionContext) {
                    $contexts[$fiber] = $previous;
                } else {
                    unset($contexts[$fiber]);
                }
            }
        }
    }

    public static function sleep(float $seconds): void
    {
        if ($seconds <= 0.0) {
            return;
        }

        self::checkpoint();
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
        return self::runtime()?->supports($capability) ?? false;
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
