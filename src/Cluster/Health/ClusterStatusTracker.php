<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cluster\Health;

final class ClusterStatusTracker
{
    private ?int $lastConsumeCount = null;

    private ?int $lastConsumedAt = null;

    private ?string $lastConsumeError = null;

    private ?int $lastRecoveryAt = null;

    public function recordConsume(int $count, bool $recovered): void
    {
        $this->lastConsumedAt = time();
        $this->lastConsumeCount = $count;
        $this->lastConsumeError = null;
        if ($recovered) {
            $this->recordRecovery();
        }
    }

    public function recordFailure(\Throwable $exception): void
    {
        $this->lastConsumedAt = time();
        $this->lastConsumeError = $exception->getMessage();
    }

    public function recordRecovery(): void
    {
        $this->lastRecoveryAt = time();
    }

    public function snapshot(
        string $cluster,
        string $nodeId,
        ?string $cursor,
        ?int $cursorUpdatedAt,
        ?string $oldestAvailableEventId,
        ?string $newestAvailableEventId,
        ?int $pendingEventCount,
    ): ClusterStatus {
        return new ClusterStatus(
            $cluster,
            $nodeId,
            $cursor,
            $cursorUpdatedAt,
            $oldestAvailableEventId,
            $newestAvailableEventId,
            $pendingEventCount,
            $this->lastConsumedAt,
            $this->lastConsumeCount,
            $this->lastConsumeError,
            $this->lastRecoveryAt,
        );
    }
}
