<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Integration\Runwire;

use Infocyph\CacheLayer\Cluster\ClusterRuntime;
use Infocyph\CacheLayer\Node\Maintenance\NodeCacheMaintenance;
use Infocyph\Runwire\Coroutine\CoroutineScope;
use Infocyph\Runwire\Coroutine\Task;
use Infocyph\Runwire\Runtime\Enum\RuntimeCapability;
use Infocyph\Runwire\Supervisor\WorkerContext;
use InvalidArgumentException;

final class RunwireWorkerIntegration
{
    public static function startClusterConsumer(
        WorkerContext $worker,
        ClusterRuntime $cluster,
        int $batchSize = 1_000,
        float $idleSeconds = 0.1,
    ): ?Task {
        self::validateClusterOptions($batchSize, $idleSeconds);
        if (!self::available($worker)) {
            return null;
        }

        return $worker->spawnBackground(
            static function (CoroutineScope $scope) use ($worker, $cluster, $batchSize, $idleSeconds): void {
                RunwireIntegration::share(
                    null,
                    $scope,
                    static function () use ($worker, $cluster, $scope, $batchSize, $idleSeconds): void {
                        while ($worker->acceptingBackgroundWork()) {
                            RunwireIntegration::checkpoint();
                            $processed = $cluster->consume($batchSize);
                            if (!$worker->acceptingBackgroundWork()) {
                                break;
                            }

                            if ($processed < $batchSize && $idleSeconds > 0.0) {
                                RunwireIntegration::sleep($idleSeconds);
                            } else {
                                $scope->yieldNow();
                            }
                        }
                    },
                );
            },
        );
    }

    public static function startNodeMaintenance(
        WorkerContext $worker,
        NodeCacheMaintenance $maintenance,
        float $intervalSeconds = 60.0,
        int $pruneLimit = 5_000,
        int $optimizeEvery = 0,
    ): ?Task {
        self::validateMaintenanceOptions($intervalSeconds, $pruneLimit, $optimizeEvery);
        if (!self::available($worker)) {
            return null;
        }

        return $worker->spawnBackground(
            static function (CoroutineScope $scope) use (
                $worker,
                $maintenance,
                $intervalSeconds,
                $pruneLimit,
                $optimizeEvery,
            ): void {
                RunwireIntegration::share(
                    null,
                    $scope,
                    static function () use (
                        $worker,
                        $maintenance,
                        $intervalSeconds,
                        $pruneLimit,
                        $optimizeEvery,
                    ): void {
                        $cycles = 0;
                        while ($worker->acceptingBackgroundWork()) {
                            RunwireIntegration::checkpoint();
                            ++$cycles;
                            $maintenance->cycle(
                                pruneLimit: $pruneLimit,
                                checkpoint: true,
                                optimize: $optimizeEvery > 0 && ($cycles % $optimizeEvery) === 0,
                            );
                            if (!$worker->acceptingBackgroundWork()) {
                                break;
                            }

                            RunwireIntegration::sleep($intervalSeconds);
                        }
                    },
                );
            },
        );
    }

    private static function available(WorkerContext $worker): bool
    {
        return $worker->role->background()
            && $worker->acceptingBackgroundWork()
            && RunwireIntegration::supports(RuntimeCapability::RUNWIRE_COROUTINES)
            && RunwireIntegration::supports(RuntimeCapability::RUNWIRE_LOOP_AVAILABLE);
    }

    private static function validateClusterOptions(int $batchSize, float $idleSeconds): void
    {
        if ($batchSize < 1) {
            throw new InvalidArgumentException('Runwire cluster consumer batch size must be greater than zero.');
        }
        if (!is_finite($idleSeconds) || $idleSeconds < 0.0 || $idleSeconds > 60.0) {
            throw new InvalidArgumentException('Runwire cluster consumer idle interval must be between 0 and 60 seconds.');
        }
    }

    private static function validateMaintenanceOptions(
        float $intervalSeconds,
        int $pruneLimit,
        int $optimizeEvery,
    ): void {
        if (!is_finite($intervalSeconds) || $intervalSeconds < 0.001 || $intervalSeconds > 86_400.0) {
            throw new InvalidArgumentException('Runwire maintenance interval must be between 0.001 and 86400 seconds.');
        }
        if ($pruneLimit < 1) {
            throw new InvalidArgumentException('Runwire maintenance prune limit must be greater than zero.');
        }
        if ($optimizeEvery < 0) {
            throw new InvalidArgumentException('Runwire maintenance optimize cadence cannot be negative.');
        }
    }
}
