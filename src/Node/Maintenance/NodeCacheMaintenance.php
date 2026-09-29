<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Node\Maintenance;

use PDO;

final readonly class NodeCacheMaintenance
{
    public function __construct(
        private PDO $connection,
        private NodeCachePruner $pruner,
    ) {}

    public function checkpoint(): void
    {
        $this->connection->query('PRAGMA wal_checkpoint(PASSIVE)');
    }

    public function cycle(
        int $pruneLimit = 5_000,
        bool $checkpoint = true,
        bool $optimize = false,
    ): int {
        $pruned = $this->pruneExpired($pruneLimit);
        if ($checkpoint) {
            $this->checkpoint();
        }
        if ($optimize) {
            $this->optimize();
        }

        return $pruned;
    }

    public function optimize(): void
    {
        $this->connection->exec('PRAGMA optimize');
    }

    public function pruneExpired(int $limit = 5_000): int
    {
        return $this->pruner->pruneExpired($limit);
    }
}
