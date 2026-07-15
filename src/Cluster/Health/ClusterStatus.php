<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cluster\Health;

final readonly class ClusterStatus
{
    public function __construct(
        public string $cluster,
        public string $nodeId,
        public ?string $cursor,
        public ?int $cursorUpdatedAt,
        public ?string $oldestAvailableEventId,
        public ?string $newestAvailableEventId,
        public ?int $pendingEventCount,
        public ?int $lastConsumedAt,
        public ?int $lastConsumeCount,
        public ?string $lastConsumeError,
        public ?int $lastRecoveryAt,
    ) {}
}
