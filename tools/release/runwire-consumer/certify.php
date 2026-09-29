<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\CacheLayer\Cluster\ClusterCache;
use Infocyph\CacheLayer\Cluster\ClusterCacheConfig;
use Infocyph\CacheLayer\Cluster\Event\InvalidationEvent;
use Infocyph\CacheLayer\Cluster\Transport\Pdo\PdoInvalidationTransport;
use Infocyph\CacheLayer\Integration\Runwire\RunwireIntegration;
use Infocyph\CacheLayer\Node\NodeCache;
use Infocyph\CacheLayer\Node\NodeCacheConfig;
use Infocyph\Runwire\RequestContext;
use Infocyph\Runwire\Runtime\Enum\RuntimeDriver;
use Infocyph\Runwire\RuntimeCapabilities;
use Infocyph\Runwire\RuntimeContext;

require __DIR__ . '/vendor/autoload.php';

const ITERATIONS = 4_000;
const WARMUP = 250;
const MIN_RPM_RATIO = 0.50;
const MAX_P99_MULTIPLIER = 3.0;
const MAX_EXTRA_P99_MS = 1.0;
const MAX_MEMORY_DELTA_BYTES = 8_388_608;

/** @param list<float> $samples */
function percentile(array $samples, float $percentile): float
{
    sort($samples, SORT_NUMERIC);
    $index = (int) floor((count($samples) - 1) * $percentile);

    return (float) ($samples[$index] ?? 0.0);
}

/** @param array<string, mixed> $usage */
function usageValue(array $usage, string $key): int
{
    $value = $usage[$key] ?? 0;

    return is_int($value) ? $value : (int) $value;
}

/**
 * @param array<string, mixed> $start
 * @param array<string, mixed> $end
 */
function cpuMicros(array $start, array $end): int
{
    return (usageValue($end, 'ru_utime.tv_sec') - usageValue($start, 'ru_utime.tv_sec')) * 1_000_000
        + usageValue($end, 'ru_utime.tv_usec') - usageValue($start, 'ru_utime.tv_usec')
        + (usageValue($end, 'ru_stime.tv_sec') - usageValue($start, 'ru_stime.tv_sec')) * 1_000_000
        + usageValue($end, 'ru_stime.tv_usec') - usageValue($start, 'ru_stime.tv_usec');
}

function runCacheOperation(Cache $cache, int $index): void
{
    $tenant = $index % 32;
    $key = 'tenant-' . $tenant . '-item-' . ($index % 256);

    if (($index % 8) === 0) {
        if (!$cache->set($key, $index, 60)) {
            throw new RuntimeException('Cache write failed.');
        }

        return;
    }

    $cache->get($key);
}

function cleanupDirectory(string $base): void
{
    if (!is_dir($base)) {
        return;
    }

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($files as $file) {
        if (!$file instanceof SplFileInfo) {
            continue;
        }

        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($base);
}

/** @return array<string, int|float> */
function workload(RuntimeContext $runtime, bool $integrated): array
{
    $cache = Cache::memory($integrated ? 'runwire-on' : 'runwire-off');
    $latencies = [];
    $errors = 0;
    $startMemory = memory_get_usage(true);
    $startCpu = getrusage();
    $start = hrtime(true);

    if ($integrated) {
        RunwireIntegration::bind($runtime);
    } else {
        RunwireIntegration::release();
    }

    for ($index = 0; $index < ITERATIONS + WARMUP; ++$index) {
        $request = RequestContext::create($runtime);
        $started = hrtime(true);

        try {
            $operation = static function () use ($cache, $index): void {
                runCacheOperation($cache, $index);
            };
            if ($integrated) {
                RunwireIntegration::share($request, null, $operation);
            } else {
                $operation();
            }
        } catch (Throwable) {
            ++$errors;
        } finally {
            $request->complete();
        }

        if ($index >= WARMUP) {
            $latencies[] = (hrtime(true) - $started) / 1_000_000;
        }
    }

    $elapsed = (hrtime(true) - $start) / 1_000_000_000;
    $endCpu = getrusage();
    $metrics = $cache->exportMetrics();
    $cpuMicros = cpuMicros($startCpu, $endCpu);

    RunwireIntegration::release($runtime);
    gc_collect_cycles();

    return [
        'rpm' => ITERATIONS / max($elapsed, 0.000001) * 60,
        'p50_ms' => percentile($latencies, 0.50),
        'p95_ms' => percentile($latencies, 0.95),
        'p99_ms' => percentile($latencies, 0.99),
        'errors' => $errors,
        'memory_delta_bytes' => max(0, memory_get_usage(true) - $startMemory),
        'peak_memory_bytes' => memory_get_peak_usage(true),
        'cpu_ms' => $cpuMicros / 1_000,
        'backend_gets' => (int) ($metrics['get'] ?? 0),
        'backend_sets' => (int) ($metrics['set'] ?? 0),
    ];
}

/** @return array<string, int|float> */
function invalidationAndMaintenance(): array
{
    $base = sys_get_temp_dir() . '/cachelayer-runwire-cert-' . bin2hex(random_bytes(6));
    mkdir($base, 0700, true);

    try {
        $node = new NodeCacheConfig($base . '/node.sqlite', 'certification', apcuEnabled: false);
        $transport = new PdoInvalidationTransport(
            new PDO('sqlite:' . $base . '/events.sqlite'),
            allowSqliteForTesting: true,
        );
        $cluster = ClusterCache::create(
            $node,
            new ClusterCacheConfig('certification', 'consumer', 'sqlite-cert', consumerBatchSize: 100),
            $transport,
        );

        for ($index = 0; $index < 100; ++$index) {
            $transport->publish(
                InvalidationEvent::key(
                    'certification',
                    'certification',
                    'key-' . $index,
                    'publisher',
                ),
            );
        }

        $started = hrtime(true);
        $processed = $cluster->drain(limit: 25, maxBatches: 8);
        $lagMs = (hrtime(true) - $started) / 1_000_000;

        $connection = new PDO('sqlite:' . $base . '/node.sqlite');
        $statement = $connection->prepare(
            'INSERT INTO cachelayer_node_entries (namespace, cache_key, payload, expires_at) VALUES (?, ?, ?, ?)',
        );
        for ($index = 0; $index < 100; ++$index) {
            $statement->execute(['certification', 'expired-' . $index, 'unused', time() - 1]);
        }

        $maintenance = NodeCache::maintenance($node);
        $samples = [];
        for ($cycle = 0; $cycle < 10; ++$cycle) {
            $cycleStarted = hrtime(true);
            $maintenance->cycle(pruneLimit: 10, checkpoint: true, optimize: $cycle === 9);
            $samples[] = (hrtime(true) - $cycleStarted) / 1_000_000;
        }

        return [
            'invalidation_events' => $processed,
            'invalidation_lag_ms' => $lagMs,
            'maintenance_p95_ms' => percentile($samples, 0.95),
            'pending_events' => $cluster->status()->pendingEventCount ?? -1,
        ];
    } finally {
        cleanupDirectory($base);
    }
}

$capabilities = new RuntimeCapabilities(
    driver: RuntimeDriver::NATIVE,
    persistentProcess: true,
    persistentApplication: true,
    runwireLoopAvailable: true,
    supportsRunwireCoroutines: true,
);
$runtime = RuntimeContext::fromCapabilities(
    $capabilities,
    'cachelayer-certification',
    workerSlot: 0,
    generation: 1,
    concurrent: true,
);

$baseline = workload($runtime, false);
$integrated = workload($runtime, true);
$operational = invalidationAndMaintenance();

$ratio = $integrated['rpm'] / max((float) $baseline['rpm'], 0.000001);
$p99Budget = ((float) $baseline['p99_ms'] * MAX_P99_MULTIPLIER) + MAX_EXTRA_P99_MS;

$report = [
    'php' => PHP_VERSION,
    'runwire' => Composer\InstalledVersions::getPrettyVersion('infocyph/runwire'),
    'iterations' => ITERATIONS,
    'baseline' => $baseline,
    'integrated' => $integrated,
    'rpm_ratio' => $ratio,
    'budgets' => [
        'minimum_rpm_ratio' => MIN_RPM_RATIO,
        'maximum_integrated_p99_ms' => $p99Budget,
        'maximum_memory_delta_bytes' => MAX_MEMORY_DELTA_BYTES,
        'errors' => 0,
    ],
    'operational' => $operational,
];

fwrite(STDOUT, json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL);

if (
    $baseline['errors'] !== 0
    || $integrated['errors'] !== 0
    || $ratio < MIN_RPM_RATIO
    || $integrated['p99_ms'] > $p99Budget
    || $integrated['memory_delta_bytes'] > MAX_MEMORY_DELTA_BYTES
    || $operational['invalidation_events'] !== 100
    || $operational['pending_events'] !== 0
) {
    throw new RuntimeException('Runwire matched certification workload exceeded a release budget.');
}
