<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cluster\ClusterCache;
use Infocyph\CacheLayer\Cluster\ClusterCacheConfig;
use Infocyph\CacheLayer\Cluster\Event\InvalidationEvent;
use Infocyph\CacheLayer\Cluster\Transport\Pdo\PdoInvalidationTransport;
use Infocyph\CacheLayer\Integration\Runwire\RunwireIntegration;
use Infocyph\CacheLayer\Integration\Runwire\RunwireWorkerIntegration;
use Infocyph\CacheLayer\Node\NodeCacheConfig;
use Infocyph\Runwire\Loop\SelectLoop;
use Infocyph\Runwire\Runtime\Enum\RuntimeDriver;
use Infocyph\Runwire\RuntimeCapabilities;
use Infocyph\Runwire\RuntimeContext;
use Infocyph\Runwire\Supervisor\Enum\WorkerRole;
use Infocyph\Runwire\Supervisor\WorkerContext;

$autoload = getenv('CACHELAYER_EXAMPLE_AUTOLOAD');
if (!is_string($autoload) || $autoload === '') {
    $autoload = dirname(__DIR__) . '/vendor/autoload.php';
}
require $autoload;

$base = sys_get_temp_dir() . '/cachelayer-runwire-example-' . bin2hex(random_bytes(6));
$readyParent = null;
$worker = null;
$runtime = null;

try {
    $transportConnection = new PDO('sqlite:' . $base . '/invalidation.sqlite');
    $transport = new PdoInvalidationTransport(
        $transportConnection,
        allowSqliteForTesting: true,
    );
    $node = new NodeCacheConfig(
        $base . '/node.sqlite',
        'application',
        apcuEnabled: false,
    );
    $cluster = ClusterCache::create(
        $node,
        new ClusterCacheConfig(
            'example-cluster',
            'node-a',
            'example-sqlite-transport',
            consumerBatchSize: 10,
        ),
        $transport,
    );
    $cluster->cache()->set('demo-key', 'cached', 60);
    $transport->publish(
        InvalidationEvent::key(
            'example-cluster',
            'application',
            'demo-key',
            'node-b',
        ),
    );

    $capabilities = new RuntimeCapabilities(
        driver: RuntimeDriver::NATIVE,
        persistentProcess: true,
        persistentApplication: true,
        ownsEventLoop: true,
        runwireLoopAvailable: true,
        supportsRunwireCoroutines: true,
    );
    $runtime = RuntimeContext::fromCapabilities(
        $capabilities,
        'cachelayer-example',
        workerSlot: 0,
        generation: 1,
        concurrent: true,
    );
    RunwireIntegration::bind($runtime);

    [$readyParent, $readyChild] = stream_socket_pair(
        STREAM_PF_UNIX,
        STREAM_SOCK_STREAM,
        STREAM_IPPROTO_IP,
    );
    $pid = getmypid();
    $worker = new WorkerContext(
        group: 'cachelayer-example',
        slot: 0,
        generation: 1,
        pid: is_int($pid) ? $pid : 0,
        parentPid: 0,
        readyStream: $readyChild,
        role: WorkerRole::TASK,
    );

    $loop = new SelectLoop();
    $worker->attachLoop($loop, backgroundShutdownGraceSeconds: 0.25);
    $task = RunwireWorkerIntegration::startClusterConsumer(
        $worker,
        $cluster,
        batchSize: 10,
        idleSeconds: 0.001,
    );
    if ($task === null) {
        throw new RuntimeException('The active Runwire context does not expose the required worker capabilities.');
    }

    $loop->delay(0.02, static function () use ($worker): void {
        $worker->requestStop();
    });
    $loop->run();

    $status = $cluster->status();
    if ($cluster->cache()->get('demo-key') !== null) {
        throw new RuntimeException('The invalidation worker did not clear the cached key.');
    }
    if ($status->cursor !== '1' || $status->pendingEventCount !== 0 || $status->lastConsumeError !== null) {
        throw new RuntimeException('The invalidation worker did not persist clean cursor progress.');
    }

    fwrite(STDOUT, "CacheLayer Runwire invalidation worker example passed.\n");
} finally {
    $worker?->close();
    if (is_resource($readyParent)) {
        fclose($readyParent);
    }
    RunwireIntegration::release($runtime);

    if (is_dir($base)) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($base);
    }
}
