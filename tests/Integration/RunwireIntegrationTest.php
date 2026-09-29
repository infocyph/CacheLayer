<?php

declare(strict_types=1);

use Fiber;
use Infocyph\CacheLayer\Integration\Runwire\RunwireIntegration;
use Infocyph\Runwire\Coroutine\CoroutineRuntime;
use Infocyph\Runwire\Coroutine\CoroutineScope;
use Infocyph\Runwire\RequestContext;
use Infocyph\Runwire\Runtime\Enum\RuntimeCapability;
use Infocyph\Runwire\Runtime\Enum\RuntimeDriver;
use Infocyph\Runwire\RuntimeCapabilities;
use Infocyph\Runwire\RuntimeContext;

function cacheLayerRunwireContext(bool $persistent = true, bool $concurrent = false): RuntimeContext
{
    return RuntimeContext::fromCapabilities(
        new RuntimeCapabilities(
            driver: RuntimeDriver::NATIVE,
            persistentProcess: $persistent,
            persistentApplication: $persistent,
            runwireLoopAvailable: $concurrent,
            supportsRunwireCoroutines: $concurrent,
        ),
        'cachelayer-test',
        concurrent: $concurrent,
    );
}

beforeEach(function (): void {
    RunwireIntegration::release();
    flush_memoizers();
});

afterEach(function (): void {
    RunwireIntegration::release();
    flush_memoizers();
});

it('keeps normal memoization when no Runwire runtime is shared', function (): void {
    $runs = 0;
    $loader = static function () use (&$runs): int {
        return ++$runs;
    };

    expect(memoize($loader))->toBe(1)
        ->and(memoize($loader))->toBe(1)
        ->and($runs)->toBe(1);
});

it('isolates memoizers by the shared Runwire request context', function (): void {
    $runtime = cacheLayerRunwireContext();
    RunwireIntegration::bind($runtime);
    $runs = 0;
    $loader = static function () use (&$runs): int {
        return ++$runs;
    };

    $first = RequestContext::create($runtime);
    $firstValues = RunwireIntegration::share(
        $first,
        null,
        static fn(): array => [memoize($loader), memoize($loader)],
    );
    $first->complete();

    $second = RequestContext::create($runtime);
    $secondValues = RunwireIntegration::share(
        $second,
        null,
        static fn(): array => [memoize($loader), memoize($loader)],
    );
    $second->complete();

    expect($firstValues)->toBe([1, 1])
        ->and($secondValues)->toBe([2, 2])
        ->and($runs)->toBe(2);
});

it('bypasses process-global memoization in concurrent persistent runtimes without a shared request', function (): void {
    $runtime = cacheLayerRunwireContext(concurrent: true);
    RunwireIntegration::bind($runtime);
    $runs = 0;
    $loader = static function () use (&$runs): int {
        return ++$runs;
    };

    expect(memoize($loader))->toBe(1)
        ->and(memoize($loader))->toBe(2)
        ->and($runs)->toBe(2);
});

it('keeps concurrent request memoizers isolated by fiber', function (): void {
    $runtime = cacheLayerRunwireContext(concurrent: true);
    RunwireIntegration::bind($runtime);
    $requestA = RequestContext::create($runtime);
    $requestB = RequestContext::create($runtime);
    $runsA = 0;
    $runsB = 0;
    $loaderA = static function () use (&$runsA): int {
        return ++$runsA;
    };
    $loaderB = static function () use (&$runsB): int {
        return ++$runsB;
    };

    $fiberA = new Fiber(static fn(): int => RunwireIntegration::share(
        $requestA,
        null,
        static function () use ($loaderA): int {
            $first = memoize($loaderA);
            Fiber::suspend($first);

            return memoize($loaderA);
        },
    ));
    $fiberB = new Fiber(static fn(): int => RunwireIntegration::share(
        $requestB,
        null,
        static function () use ($loaderB): int {
            $first = memoize($loaderB);
            Fiber::suspend($first);

            return memoize($loaderB);
        },
    ));

    expect($fiberA->start())->toBe(1)
        ->and($fiberB->start())->toBe(1);
    $fiberA->resume();
    $fiberB->resume();

    expect($fiberA->getReturn())->toBe(1)
        ->and($fiberB->getReturn())->toBe(1)
        ->and($runsA)->toBe(1)
        ->and($runsB)->toBe(1);
});

it('shares the active Runwire task scope and capability set', function (): void {
    $runtime = cacheLayerRunwireContext(concurrent: true);
    $request = RequestContext::create($runtime);
    RunwireIntegration::bind($runtime);
    $coroutines = new CoroutineRuntime();

    $result = $coroutines->run(
        static function (CoroutineScope $scope) use ($request): array {
            return RunwireIntegration::share(
                $request,
                $scope,
                static fn(): array => [
                    RunwireIntegration::current()?->scope === $scope,
                    RunwireIntegration::supports(RuntimeCapability::RUNWIRE_COROUTINES),
                ],
            );
        },
    );

    expect($result)->toBe([true, true]);
});

it('restores the normal path after the shared Runwire runtime is released', function (): void {
    $runtime = cacheLayerRunwireContext(concurrent: true);
    RunwireIntegration::bind($runtime);
    RunwireIntegration::release($runtime);
    $runs = 0;
    $loader = static function () use (&$runs): int {
        return ++$runs;
    };

    expect(RunwireIntegration::runtime())->toBeNull()
        ->and(memoize($loader))->toBe(1)
        ->and(memoize($loader))->toBe(1)
        ->and($runs)->toBe(1);
});


it('replaces runtime bindings without retaining a previous worker request scope', function (): void {
    $firstRuntime = cacheLayerRunwireContext(concurrent: true);
    $secondRuntime = RuntimeContext::fromCapabilities(
        new RuntimeCapabilities(
            driver: RuntimeDriver::NATIVE,
            persistentProcess: true,
            persistentApplication: true,
            runwireLoopAvailable: true,
            supportsRunwireCoroutines: true,
        ),
        'cachelayer-replacement-test',
        workerSlot: 1,
        generation: 2,
        concurrent: true,
    );
    $oldRequest = RequestContext::create($firstRuntime);

    RunwireIntegration::bind($firstRuntime);
    RunwireIntegration::share(
        $oldRequest,
        null,
        static fn(): int => memoize(static fn(): int => 1),
    );
    RunwireIntegration::bind($secondRuntime);

    expect(RunwireIntegration::runtime())->toBe($secondRuntime)
        ->and(RunwireIntegration::current())->toBeNull();

    expect(fn(): mixed => RunwireIntegration::share(
        $oldRequest,
        null,
        static fn(): string => 'stale',
    ))->toThrow(LogicException::class, 'different runtime');
});


it('releases request-owned memoizers across repeated persistent request lifecycles', function (): void {
    $runtime = cacheLayerRunwireContext(concurrent: true);
    RunwireIntegration::bind($runtime);
    $references = [];

    for ($index = 0; $index < 128; ++$index) {
        $request = RequestContext::create($runtime);
        $memoizer = RunwireIntegration::share(
            $request,
            null,
            static fn(): mixed => memoize(),
        );
        $references[] = WeakReference::create($memoizer);
        $request->complete();
        unset($memoizer, $request);
    }

    gc_collect_cycles();

    foreach ($references as $reference) {
        expect($reference->get())->toBeNull();
    }
});


it('flushing one Runwire request does not change another live request memoizer identity', function (): void {
    $runtime = cacheLayerRunwireContext(concurrent: true);
    RunwireIntegration::bind($runtime);
    $requestA = RequestContext::create($runtime);
    $requestB = RequestContext::create($runtime);
    $runs = 0;
    $callback = static function () use (&$runs): int {
        return ++$runs;
    };

    $result = RunwireIntegration::share(
        $requestA,
        null,
        static function () use ($requestB, $callback, &$runs): array {
            $first = memoize($callback);

            RunwireIntegration::share(
                $requestB,
                null,
                static function (): void {
                    memoize();
                    once(static fn(): string => 'request-b');
                    flush_memoizers();
                },
            );

            return [$first, memoize($callback), $runs];
        },
    );

    expect($result)->toBe([1, 1, 1]);
});
