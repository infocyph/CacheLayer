<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Node\Maintenance;

use PDO;

final readonly class NodeCachePruner
{
    public function __construct(
        private PDO $connection,
        private string $namespace,
    ) {}

    public function pruneExpired(int $limit = 5_000): int
    {
        if ($limit < 1) {
            return 0;
        }

        $statement = $this->connection->prepare(
            <<<'SQL'
                DELETE FROM cachelayer_node_entries
                WHERE (namespace, cache_key) IN (
                    SELECT namespace, cache_key
                    FROM cachelayer_node_entries
                    WHERE namespace = :namespace
                      AND expires_at IS NOT NULL
                      AND expires_at <= :current_time
                    LIMIT :limit
                )
                SQL,
        );
        $statement->bindValue(':namespace', $this->namespace, PDO::PARAM_STR);
        $statement->bindValue(':current_time', time(), PDO::PARAM_INT);
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return $statement->rowCount();
    }
}
