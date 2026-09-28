<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cluster\ClusterCache;
use Infocyph\CacheLayer\Cluster\ClusterCacheConfig;
use Infocyph\CacheLayer\Cluster\Event\InvalidationEvent;
use Infocyph\CacheLayer\Integration\Runwire\RunwireIntegration;
use Infocyph\CacheLayer\Node\NodeCacheConfig;
use Infocyph\CacheLayer\Tests\Cluster\Support\InMemoryInvalidationTransport;
use Infocyph\Runwire\Coroutine\CoroutineRuntime;
use Infocyph\Runwire\Coroutine\CoroutineScope;
use Infocyph\Runwire\Exception\CancelledException;
use Infocyph\Runwire\RequestContext;
use Infocyph\Runwire\Runtime\Enum\CancellationReason;
use Infocyph\Runwire\Runtime\Enum\RuntimeDriver;
use Infocyph\Runwire\RuntimeCapabilities;
use Infocyph\Runwire\RuntimeContext;

function cacheLayerWorkerRuntimeContext(): RuntimeContext
{
    return RuntimeContext::fromCapabilities(
        new RuntimeCapabilities(
            driver: RuntimeDriver::NATIVE,
            persistentProcess: true,
            persistentApplication: true,
            runwireLoopAvailable: true,
            supportsRunwireCoroutines: true,
        ),
        'cachelayer-worker-test',
        concurrent: true,
    );
}

beforeEach(function (): void {
    $this->runwireWorkerDirectory = sys_get_temp_dir() . '/cachelayer-runwire-worker-' . uniqid();
    $this->runwireWorkerTransport = new InMemoryInvalidationTransport();
    $this->runwireWorkerCluster = ClusterCache::create(
        new NodeCacheConfig(
            $this->runwireWorkerDirectory . '/node.sqlite',
            'application',
            apcuEnabled: false,
        ),
        new ClusterCacheConfig('runwire-cluster', 'node-a', 'runwire-memory'),
        $this->runwireWorkerTransport,
    );
    RunwireIntegration::release();
});

afterEach(function (): void {
    RunwireIntegration::release();
    if (!is_dir($this->runwireWorkerDirectory)) {
        return;
    }

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($this->runwireWorkerDirectory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($files as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($this->runwireWorkerDirectory);
});

it('polls invalidations in bounded cycles on the normal path', function (): void {
    foreach (['one', 'two', 'three'] as $key) {
        $this->runwireWorkerTransport->publish(
            InvalidationEvent::key('runwire-cluster', 'application', $key, 'writer'),
        );
    }

    expect($this->runwireWorkerCluster->poll(limit: 1, cycles: 2, idleSeconds: 0.0))->toBe(2)
        ->and($this->runwireWorkerCluster->consume())->toBe(1);
});

it('uses the shared Runwire task scope for cooperative polling waits', function (): void {
    $runtime = cacheLayerWorkerRuntimeContext();
    $request = RequestContext::create($runtime);
    $coroutines = new CoroutineRuntime();
    $order = [];
    RunwireIntegration::bind($runtime);

    $processed = $coroutines->runRequest(
        $request,
        function (CoroutineScope $scope) use ($request, &$order): int {
            $scope->spawn(static function () use (&$order): void {
                $order[] = 'child';
            });

            $result = RunwireIntegration::share(
                $request,
                $scope,
                fn(): int => $this->runwireWorkerCluster->poll(
                    limit: 1,
                    cycles: 2,
                    idleSeconds: 0.001,
                ),
            );
            $order[] = 'after';

            return $result;
        },
    );

    expect($processed)->toBe(0)
        ->and($order)->toBe(['child', 'after']);
});

it('propagates Runwire cancellation instead of replaying worker operations through fallback', function (): void {
    $runtime = cacheLayerWorkerRuntimeContext();
    $request = RequestContext::create($runtime);
    $coroutines = new CoroutineRuntime();
    RunwireIntegration::bind($runtime);

    expect(fn() => $coroutines->runRequest(
        $request,
        function (CoroutineScope $scope) use ($request): int {
            $scope->spawn(static function () use ($scope, $request): void {
                $scope->sleep(0.001);
                $request->cancel(CancellationReason::HOST_CANCELLED);
            });

            return RunwireIntegration::share(
                $request,
                $scope,
                fn(): int => $this->runwireWorkerCluster->poll(
                    limit: 1,
                    cycles: 100,
                    idleSeconds: 0.01,
                ),
            );
        },
    ))->toThrow(CancelledException::class);
});
