<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\CacheLayer\Integration\Runwire\RunwireIntegration;
use Infocyph\Runwire\Coroutine\CoroutineRuntime;
use Infocyph\Runwire\Coroutine\CoroutineScope;
use Infocyph\Runwire\Exception\CancelledException;
use Infocyph\Runwire\RequestContext;
use Infocyph\Runwire\Runtime\Enum\CancellationReason;
use Infocyph\Runwire\Runtime\Enum\RuntimeDriver;
use Infocyph\Runwire\Runtime\RequestExecutionPolicy;
use Infocyph\Runwire\RuntimeCapabilities;
use Infocyph\Runwire\RuntimeContext;

require __DIR__ . '/vendor/autoload.php';

const SEQUENTIAL_REQUESTS = 2_048;
const CONCURRENT_REQUESTS = 256;
const MAX_MEMORY_GROWTH_BYTES = 8_388_608;

$runtime = RuntimeContext::fromCapabilities(
    new RuntimeCapabilities(
        driver: RuntimeDriver::NATIVE,
        persistentProcess: true,
        persistentApplication: true,
        runwireLoopAvailable: true,
        supportsRunwireCoroutines: true,
    ),
    'cachelayer-soak',
    workerSlot: 0,
    generation: 1,
    concurrent: true,
);
RunwireIntegration::bind($runtime);

$cache = Cache::memory('runwire-soak');
$startMemory = memory_get_usage(true);
$errors = 0;
$cancellations = 0;
$deadlines = 0;
$validated = 0;

for ($index = 0; $index < SEQUENTIAL_REQUESTS; ++$index) {
    $policy = ($index % 257) === 0
        ? new RequestExecutionPolicy(maxExecutionSeconds: 0.001)
        : new RequestExecutionPolicy();
    $start = ($index % 257) === 0
        ? (int) hrtime(true) - 2_000_000
        : null;
    $request = RequestContext::create($runtime, $policy, startNanoseconds: $start);

    try {
        if (($index % 191) === 0) {
            ++$errors;
            throw new RuntimeException('intentional soak failure');
        }

        RunwireIntegration::share(
            $request,
            null,
            static function () use ($cache, $index, &$validated): void {
                $tenant = $index % 64;
                $key = 'tenant-' . $tenant . '-request-' . $index;

                $memoized = memoize(static fn(int $value): int => $value + 1, [$index]);
                if ($memoized !== $index + 1 || memoize(static fn(int $value): int => $value + 1, [$index]) !== $index + 1) {
                    throw new RuntimeException('request memoizer leaked or failed');
                }

                $item = $cache->getItem($key)->set($index);
                if (!$cache->saveDeferred($item) || !$cache->commit() || $cache->get($key) !== $index) {
                    throw new RuntimeException('deferred cache write failed during soak');
                }
                ++$validated;
            },
        );
    } catch (Throwable $exception) {
        if ($exception->getMessage() !== 'intentional soak failure') {
            throw $exception;
        }
    } finally {
        $request->complete();
    }
}

$coroutines = new CoroutineRuntime();
$coroutines->run(
    static function (CoroutineScope $scope) use (
        $runtime,
        $cache,
        &$cancellations,
        &$deadlines,
        &$validated,
    ): void {
        $tasks = [];
        for ($index = 0; $index < CONCURRENT_REQUESTS; ++$index) {
            $tasks[] = $scope->spawn(
                static function () use (
                    $runtime,
                    $scope,
                    $cache,
                    $index,
                    &$cancellations,
                    &$deadlines,
                    &$validated,
                ): void {
                    $deadlineCase = ($index % 61) === 0;
                    $policy = $deadlineCase
                        ? new RequestExecutionPolicy(maxExecutionSeconds: 0.001)
                        : new RequestExecutionPolicy();
                    $start = $deadlineCase
                        ? (int) hrtime(true) - 2_000_000
                        : null;
                    $request = RequestContext::create($runtime, $policy, startNanoseconds: $start);

                    try {
                        RunwireIntegration::share(
                            $request,
                            $scope,
                            static function () use (
                                $request,
                                $scope,
                                $cache,
                                $index,
                                $deadlineCase,
                                &$cancellations,
                                &$deadlines,
                                &$validated,
                            ): void {
                                if ($deadlineCase) {
                                    try {
                                        RunwireIntegration::checkpoint();
                                    } catch (CancelledException) {
                                        ++$deadlines;

                                        return;
                                    }
                                }

                                if (($index % 53) === 0) {
                                    $request->cancel(CancellationReason::HOST_CANCELLED);
                                    try {
                                        RunwireIntegration::checkpoint();
                                    } catch (CancelledException) {
                                        ++$cancellations;

                                        return;
                                    }
                                }

                                $tenant = $index % 16;
                                $key = 'concurrent-' . $tenant . '-' . $index;
                                $memo = memoize(static fn(int $value): int => $value * 2, [$index]);
                                $scope->yieldNow();
                                if (memoize(static fn(int $value): int => $value * 2, [$index]) !== $memo) {
                                    throw new RuntimeException('concurrent request memoizer leaked');
                                }

                                $item = $cache->getItem($key)->set($memo);
                                if (!$cache->saveDeferred($item) || !$cache->commit() || $cache->get($key) !== $memo) {
                                    throw new RuntimeException('concurrent deferred cache write failed');
                                }
                                ++$validated;
                            },
                        );
                    } finally {
                        if (!$request->completed()) {
                            $request->complete();
                        }
                    }
                },
            );
        }

        foreach ($tasks as $task) {
            $task->await();
        }
    },
);

RunwireIntegration::release($runtime);
gc_collect_cycles();
$memoryGrowth = max(0, memory_get_usage(true) - $startMemory);

$report = [
    'php' => PHP_VERSION,
    'runwire' => Composer\InstalledVersions::getPrettyVersion('infocyph/runwire'),
    'sequential_requests' => SEQUENTIAL_REQUESTS,
    'concurrent_requests' => CONCURRENT_REQUESTS,
    'intentional_failures' => $errors,
    'cancellations' => $cancellations,
    'deadlines' => $deadlines,
    'validated_requests' => $validated,
    'memory_growth_bytes' => $memoryGrowth,
    'peak_memory_bytes' => memory_get_peak_usage(true),
];

fwrite(STDOUT, json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL);

if (
    $validated < 2_000
    || $cancellations < 1
    || $deadlines < 1
    || $memoryGrowth > MAX_MEMORY_GROWTH_BYTES
    || RunwireIntegration::runtime() !== null
) {
    throw new RuntimeException('Runwire persistent-worker soak did not satisfy release invariants.');
}
