<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cluster\Cursor;

use Infocyph\CacheLayer\Cache\CacheInput;
use Infocyph\CacheLayer\Cluster\Exception\ClusterCacheException;
use Infocyph\CacheLayer\Node\Connection\NodeSqliteConnection;
use Infocyph\CacheLayer\Node\NodeCacheConfig;
use PDO;
use PDOException;

final readonly class SqliteCursorStore implements CursorStoreInterface
{
    private const string TABLE = 'cachelayer_cluster_cursors_v2';

    private PDO $connection;

    private string $namespace;

    public function __construct(
        string $sqliteFile,
        private string $cluster,
        private string $nodeId,
        string $namespace,
        ?PDO $connection = null,
    ) {
        $this->namespace = CacheInput::namespace($namespace);
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
        $cursor = $this->read(
            'SELECT last_event_id FROM ' . self::TABLE . ' '
            . 'WHERE cluster_name = :cluster AND node_id = :node_id '
            . 'AND namespace_name = :namespace LIMIT 1',
            'Unable to read the cluster cursor.',
        );

        return is_string($cursor) && $cursor !== '' ? $cursor : null;
    }

    public function reset(?string $eventId): void
    {
        $this->write($eventId);
    }

    public function updatedAt(): ?int
    {
        $updatedAt = $this->read(
            'SELECT updated_at FROM ' . self::TABLE . ' '
            . 'WHERE cluster_name = :cluster AND node_id = :node_id '
            . 'AND namespace_name = :namespace LIMIT 1',
            'Unable to read the cluster cursor update time.',
        );

        return is_int($updatedAt) || (is_string($updatedAt) && ctype_digit($updatedAt))
            ? (int) $updatedAt
            : null;
    }

    private function createSchemaIfMissing(): void
    {
        try {
            $this->connection->exec(
                'CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' ('
                . 'cluster_name TEXT NOT NULL, node_id TEXT NOT NULL, namespace_name TEXT NOT NULL, '
                . 'last_event_id TEXT, updated_at INTEGER NOT NULL, '
                . 'PRIMARY KEY (cluster_name, node_id, namespace_name)) WITHOUT ROWID',
            );
        } catch (PDOException $exception) {
            throw new ClusterCacheException('Unable to initialize the cluster cursor store.', 0, $exception);
        }
    }

    private function read(string $sql, string $failureMessage): mixed
    {
        try {
            $statement = $this->connection->prepare($sql);
            $statement->execute($this->scopeParameters());

            return $statement->fetchColumn();
        } catch (PDOException $exception) {
            throw new ClusterCacheException($failureMessage, 0, $exception);
        }
    }

    /** @return array<string, string> */
    private function scopeParameters(): array
    {
        return [
            ':cluster' => $this->cluster,
            ':node_id' => $this->nodeId,
            ':namespace' => $this->namespace,
        ];
    }

    private function write(?string $eventId): void
    {
        try {
            $statement = $this->connection->prepare(
                'INSERT INTO ' . self::TABLE . ' '
                . '(cluster_name, node_id, namespace_name, last_event_id, updated_at) '
                . 'VALUES (:cluster, :node_id, :namespace, :event_id, :updated_at) '
                . 'ON CONFLICT(cluster_name, node_id, namespace_name) DO UPDATE SET '
                . 'last_event_id = excluded.last_event_id, updated_at = excluded.updated_at',
            );
            $statement->execute($this->scopeParameters() + [
                ':event_id' => $eventId,
                ':updated_at' => time(),
            ]);
        } catch (PDOException $exception) {
            throw new ClusterCacheException('Unable to update the cluster cursor.', 0, $exception);
        }
    }
}
