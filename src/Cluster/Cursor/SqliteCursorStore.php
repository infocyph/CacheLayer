<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cluster\Cursor;

use Infocyph\CacheLayer\Cluster\Exception\ClusterCacheException;
use Infocyph\CacheLayer\Node\Connection\NodeSqliteConnection;
use Infocyph\CacheLayer\Node\NodeCacheConfig;
use PDO;
use PDOException;

final readonly class SqliteCursorStore implements CursorStoreInterface
{
    private PDO $connection;

    public function __construct(
        string $sqliteFile,
        private string $cluster,
        private string $nodeId,
        ?PDO $connection = null,
    ) {
        $this->connection = $connection ?? NodeSqliteConnection::create(
            new NodeCacheConfig($sqliteFile, 'cluster-cursor'),
        );
        $this->createSchemaIfMissing();
    }

    public function advance(string $eventId): void
    {
        if ($eventId === '') {
            throw new ClusterCacheException('Cluster cursor event IDs cannot be empty.');
        }

        $this->write($eventId);
    }

    public function current(): ?string
    {
        try {
            $statement = $this->connection->prepare(
                'SELECT last_event_id FROM cachelayer_cluster_cursors '
                . 'WHERE cluster_name = :cluster AND node_id = :node_id LIMIT 1',
            );
            $statement->execute([':cluster' => $this->cluster, ':node_id' => $this->nodeId]);
            $cursor = $statement->fetchColumn();
        } catch (PDOException $exception) {
            throw new ClusterCacheException('Unable to read the cluster cursor.', 0, $exception);
        }

        return is_string($cursor) && $cursor !== '' ? $cursor : null;
    }

    public function reset(?string $eventId): void
    {
        $this->write($eventId);
    }

    private function createSchemaIfMissing(): void
    {
        try {
            $this->connection->exec(
                'CREATE TABLE IF NOT EXISTS cachelayer_cluster_cursors ('
                . 'cluster_name TEXT NOT NULL, node_id TEXT NOT NULL, last_event_id TEXT, updated_at INTEGER NOT NULL, '
                . 'PRIMARY KEY (cluster_name, node_id)) WITHOUT ROWID',
            );
        } catch (PDOException $exception) {
            throw new ClusterCacheException('Unable to initialize the cluster cursor store.', 0, $exception);
        }
    }

    private function write(?string $eventId): void
    {
        try {
            $statement = $this->connection->prepare(
                'INSERT INTO cachelayer_cluster_cursors (cluster_name, node_id, last_event_id, updated_at) '
                . 'VALUES (:cluster, :node_id, :event_id, :updated_at) '
                . 'ON CONFLICT(cluster_name, node_id) DO UPDATE SET '
                . 'last_event_id = excluded.last_event_id, updated_at = excluded.updated_at',
            );
            $statement->execute([
                ':cluster' => $this->cluster,
                ':node_id' => $this->nodeId,
                ':event_id' => $eventId,
                ':updated_at' => time(),
            ]);
        } catch (PDOException $exception) {
            throw new ClusterCacheException('Unable to update the cluster cursor.', 0, $exception);
        }
    }
}
