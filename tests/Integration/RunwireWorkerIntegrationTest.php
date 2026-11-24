<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Tests\Integration;

use Infocyph\CacheLayer\Cluster\ClusterCache;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Infocyph\CacheLayer\Cluster\ClusterCacheConfig;
use Infocyph\CacheLayer\Cluster\Transport\InvalidationTransportInterface;
use Infocyph\CacheLayer\Cluster\Event\InvalidationBatch;
use Infocyph\CacheLayer\Cluster\Event\InvalidationEvent;
use Infocyph\CacheLayer\Integration\Runwire\RunwireIntegration;
use Infocyph\CacheLayer\Integration\Runwire\RunwireWorkerIntegration;
use Infocyph\CacheLayer\Node\Connection\NodeSqliteConnection;
use Infocyph\CacheLayer\Node\NodeCache;
use Infocyph\CacheLayer\Node\NodeCacheConfig;
use Infocyph\CacheLayer\Tests\Cluster\Support\InMemoryInvalidationTransport;
use Infocyph\Runwire\Coroutine\CoroutineRuntime;
use Infocyph\Runwire\Coroutine\CoroutineScope;
use Infocyph\Runwire\Coroutine\Enum\TaskState;
use Infocyph\Runwire\Exception\CancelledException;
use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\RequestContext;
use Infocyph\Runwire\Runtime\Enum\CancellationReason;
use Infocyph\Runwire\Runtime\Enum\RuntimeDriver;
use Infocyph\Runwire\RuntimeCapabilities;
use Infocyph\Runwire\RuntimeContext;
use Infocyph\Runwire\Supervisor\Enum\ShutdownReason;
use Infocyph\Runwire\Supervisor\Enum\WorkerRole;
use Infocyph\Runwire\Supervisor\WorkerContext;

/**
 * @throws \InvalidArgumentException
 */
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


/**
 * @return array{WorkerContext, resource}
 * @throws RuntimeException
 * @throws \Throwable
 */
function cacheLayerBackgroundWorkerContext(): array
{
    $readyPair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    if ($readyPair === false) {
        throw new RuntimeException('Unable to create worker readiness socket pair.');
    }
    [$readyParent, $readyChild] = $readyPair;
    $pid = getmypid();

    return [
        new WorkerContext(
            group: 'cachelayer',
            slot: 0,
            generation: 1,
            pid: is_int($pid) ? $pid : 0,
            parentPid: 0,
            readyStream: $readyChild,
            role: WorkerRole::TASK,
        ),
        $readyParent,
    ];
}

$runwireFixture = null;

beforeEach(function () use (&$runwireFixture): void {
    $runwireWorkerDirectory = sys_get_temp_dir() . '/cachelayer-runwire-worker-' . uniqid();
    $runwireWorkerTransport = new InMemoryInvalidationTransport();
    $runwireWorkerNodeConfig = new NodeCacheConfig(
        $runwireWorkerDirectory . '/node.sqlite',
        'application',
        apcuEnabled: false,
    );
    $runwireWorkerCluster = ClusterCache::create(
        $runwireWorkerNodeConfig,
        new ClusterCacheConfig('runwire-cluster', 'node-a', 'runwire-memory'),
        $runwireWorkerTransport,
    );
    RunwireIntegration::release();
    $runwireFixture = new class($runwireWorkerDirectory, $runwireWorkerTransport, $runwireWorkerNodeConfig, $runwireWorkerCluster) {
        public function __construct(
            public string $runwireWorkerDirectory,
            public InMemoryInvalidationTransport $runwireWorkerTransport,
            public NodeCacheConfig $runwireWorkerNodeConfig,
            public \Infocyph\CacheLayer\Cluster\ClusterRuntime $runwireWorkerCluster,
        ) {}
    };

});

afterEach(function () use (&$runwireFixture): void {
    if ($runwireFixture === null) {
        return;
    }

    RunwireIntegration::release();
    if (!is_dir($runwireFixture->runwireWorkerDirectory)) {
        return;
    }

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($runwireFixture->runwireWorkerDirectory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($files as $file) {
        if (!$file instanceof \SplFileInfo) {
            throw new \UnexpectedValueException('Unexpected filesystem iterator entry.');
        }
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($runwireFixture->runwireWorkerDirectory);
    $runwireFixture = null;
});

it('polls invalidations in bounded cycles on the normal path', function () use (&$runwireFixture): void {
    if ($runwireFixture === null) {
        throw new \RuntimeException('Integration fixture setup did not run.');
    }

    foreach (['one', 'two', 'three'] as $key) {
        $runwireFixture->runwireWorkerTransport->publish(
            InvalidationEvent::key('runwire-cluster', 'application', $key, 'writer'),
        );
    }

    expect($runwireFixture->runwireWorkerCluster->poll(limit: 1, cycles: 2, idleSeconds: 0.0))->toBe(2)
        ->and($runwireFixture->runwireWorkerCluster->consume())->toBe(1);
});

it('uses the shared Runwire task scope for cooperative polling waits', function () use (&$runwireFixture): void {
    if ($runwireFixture === null) {
        throw new \RuntimeException('Integration fixture setup did not run.');
    }

    $runtime = cacheLayerWorkerRuntimeContext();
    $request = RequestContext::create($runtime);
    $coroutines = new CoroutineRuntime();
    $order = [];
    RunwireIntegration::bind($runtime);

    $processed = $coroutines->runRequest(
        $request,
        function (CoroutineScope $scope) use ($request, &$order, $runwireFixture): int {
            $scope->spawn(static function () use (&$order): void {
                $order[] = 'child';
            });

            $result = RunwireIntegration::share(
                $request,
                $scope,
                fn(): int => $runwireFixture->runwireWorkerCluster->poll(
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

it('propagates Runwire cancellation instead of replaying worker operations through fallback', function () use (&$runwireFixture): void {
    if ($runwireFixture === null) {
        throw new \RuntimeException('Integration fixture setup did not run.');
    }

    $runtime = cacheLayerWorkerRuntimeContext();
    $request = RequestContext::create($runtime);
    $coroutines = new CoroutineRuntime();
    RunwireIntegration::bind($runtime);

    expect(fn() => $coroutines->runRequest(
        $request,
        function (CoroutineScope $scope) use ($request, $runwireFixture): int {
            $scope->spawn(static function () use ($scope, $request): void {
                $scope->sleep(0.001);
                $request->cancel(CancellationReason::HOST_CANCELLED);
            });

            return RunwireIntegration::share(
                $request,
                $scope,
                fn(): int => $runwireFixture->runwireWorkerCluster->poll(
                    limit: 1,
                    cycles: 100,
                    idleSeconds: 0.01,
                ),
            );
        },
    ))->toThrow(CancelledException::class);
});


it('runs bounded invalidation consumption inside the host-owned Runwire worker scope', function () use (&$runwireFixture): void {
    if ($runwireFixture === null) {
        throw new \RuntimeException('Integration fixture setup did not run.');
    }

    foreach (['one', 'two', 'three'] as $key) {
        $runwireFixture->runwireWorkerTransport->publish(
            InvalidationEvent::key('runwire-cluster', 'application', $key, 'writer'),
        );
    }

    $runtime = cacheLayerWorkerRuntimeContext();
    RunwireIntegration::bind($runtime);
    [$worker, $readyParent] = cacheLayerBackgroundWorkerContext();
    $loop = new SelectLoop();
    $worker->attachLoop($loop, 0.25);
    $task = RunwireWorkerIntegration::startClusterConsumer(
        $worker,
        $runwireFixture->runwireWorkerCluster,
        batchSize: 1,
        idleSeconds: 0.001,
    );
    expect($task)->not->toBeNull();

    $loop->delay(0.01, static function () use ($worker): void {
        $worker->requestStop();
    });
    $loop->run();

    expect($runwireFixture->runwireWorkerCluster->consume())->toBe(0)
        ->and($task?->state())->toBe(TaskState::CANCELLED)
        ->and($worker->acceptingBackgroundWork())->toBeFalse();

    $worker->close();
    fclose($readyParent);
});

it('runs bounded node maintenance inside the host-owned Runwire worker scope', function () use (&$runwireFixture): void {
    if ($runwireFixture === null) {
        throw new \RuntimeException('Integration fixture setup did not run.');
    }

    $connection = NodeSqliteConnection::create($runwireFixture->runwireWorkerNodeConfig);
    $statement = $connection->prepare(
        'INSERT INTO cachelayer_node_entries (namespace, cache_key, payload, expires_at) VALUES (?, ?, ?, ?)',
    );
    $statement->execute([
        $runwireFixture->runwireWorkerNodeConfig->namespace,
        'expired-runwire',
        'unused',
        time() - 1,
    ]);

    $runtime = cacheLayerWorkerRuntimeContext();
    RunwireIntegration::bind($runtime);
    [$worker, $readyParent] = cacheLayerBackgroundWorkerContext();
    $loop = new SelectLoop();
    $worker->attachLoop($loop, 0.25);
    $task = RunwireWorkerIntegration::startNodeMaintenance(
        $worker,
        NodeCache::maintenance($runwireFixture->runwireWorkerNodeConfig),
        intervalSeconds: 0.001,
        pruneLimit: 1,
        optimizeEvery: 2,
    );
    expect($task)->not->toBeNull();

    $loop->delay(0.01, static function () use ($worker): void {
        $worker->requestStop();
    });
    $loop->run();

    expect(\Infocyph\CacheLayer\Tests\Support\CacheFixture::countRows(
        $connection,
        "SELECT COUNT(*) FROM cachelayer_node_entries WHERE cache_key = 'expired-runwire'",
    ))->toBe(0)
        ->and($task?->state())->toBe(TaskState::CANCELLED)
        ->and($worker->backgroundDrainExpired())->toBeFalse();

    $worker->close();
    fclose($readyParent);
});

it('keeps worker automation inactive when the shared runtime lacks Runwire coroutine capabilities', function () use (&$runwireFixture): void {
    if ($runwireFixture === null) {
        throw new \RuntimeException('Integration fixture setup did not run.');
    }

    $runtime = RuntimeContext::fromCapabilities(
        new RuntimeCapabilities(driver: RuntimeDriver::NATIVE),
        'cachelayer-worker-fallback',
        concurrent: false,
    );
    RunwireIntegration::bind($runtime);
    [$worker, $readyParent] = cacheLayerBackgroundWorkerContext();

    expect(RunwireWorkerIntegration::startClusterConsumer(
        $worker,
        $runwireFixture->runwireWorkerCluster,
    ))->toBeNull()
        ->and(RunwireWorkerIntegration::startNodeMaintenance(
            $worker,
            NodeCache::maintenance($runwireFixture->runwireWorkerNodeConfig),
        ))->toBeNull();

    $worker->close();
    fclose($readyParent);
});


it('turns an unhandled CacheLayer consumer failure into a Runwire worker stop', function () use (&$runwireFixture): void {
    if ($runwireFixture === null) {
        throw new \RuntimeException('Integration fixture setup did not run.');
    }

    $transport = new class implements InvalidationTransportInterface
    {
        /**
         * @throws RuntimeException
         */
        #[\Override]
        public function consumeAfter(string $cluster, ?string $cursor, int $limit): InvalidationBatch
        {
            unset($cluster, $cursor, $limit);

            throw new RuntimeException('intentional invalidation backend failure');
        }

        #[\Override]
        public function isCursorBefore(string $cursor, string $oldestAvailableId): bool
        {
            unset($cursor, $oldestAvailableId);

            return false;
        }

        #[\Override]
        public function newestAvailableId(string $cluster): ?string
        {
            unset($cluster);

            return null;
        }

        #[\Override]
        public function oldestAvailableId(string $cluster): ?string
        {
            unset($cluster);

            return null;
        }

        #[\Override]
        public function publish(InvalidationEvent $event): string
        {
            unset($event);

            return '1';
        }
    };
    $cluster = ClusterCache::create(
        $runwireFixture->runwireWorkerNodeConfig,
        new ClusterCacheConfig('runwire-failure', 'node-a', 'runwire-failure'),
        $transport,
    );

    $runtime = cacheLayerWorkerRuntimeContext();
    RunwireIntegration::bind($runtime);
    [$worker, $readyParent] = cacheLayerBackgroundWorkerContext();
    $loop = new SelectLoop();
    $worker->attachLoop($loop, 0.25);

    $task = RunwireWorkerIntegration::startClusterConsumer(
        $worker,
        $cluster,
        batchSize: 1,
        idleSeconds: 0.001,
    );
    expect($task)->not->toBeNull();

    $loop->run();

    expect($task?->state())->toBe(TaskState::FAILED)
        ->and($worker->stopping())->toBeTrue()
        ->and($worker->shutdownReason())->toBe(ShutdownReason::FATAL_RUNTIME_ERROR)
        ->and($worker->backgroundDrainExpired())->toBeFalse();

    $worker->close();
    fclose($readyParent);
});

it('does not take worker or loop ownership for unsupported worker topology', function () use (&$runwireFixture): void {
    if ($runwireFixture === null) {
        throw new \RuntimeException('Integration fixture setup did not run.');
    }

    $runtime = cacheLayerWorkerRuntimeContext();
    RunwireIntegration::bind($runtime);

    $readyPair = stream_socket_pair(
        STREAM_PF_UNIX,
        STREAM_SOCK_STREAM,
        STREAM_IPPROTO_IP,
    );
    if ($readyPair === false) {
        throw new RuntimeException('Unable to create worker readiness socket pair.');
    }
    [$readyParent, $readyChild] = $readyPair;
    $pid = getmypid();
    $worker = new WorkerContext(
        group: 'cachelayer-http',
        slot: 0,
        generation: 1,
        pid: is_int($pid) ? $pid : 0,
        parentPid: 0,
        readyStream: $readyChild,
        role: WorkerRole::HTTP,
    );

    expect(RunwireWorkerIntegration::startClusterConsumer(
        $worker,
        $runwireFixture->runwireWorkerCluster,
    ))->toBeNull()
        ->and($worker->stopping())->toBeFalse();

    $worker->close();
    fclose($readyParent);
});
