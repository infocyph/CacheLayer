<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cluster\Transport\Pdo;

use Infocyph\CacheLayer\Cluster\Event\InvalidationBatch;
use Infocyph\CacheLayer\Cluster\Event\InvalidationEvent;
use Infocyph\CacheLayer\Cluster\Event\InvalidationEventType;
use Infocyph\CacheLayer\Cluster\Exception\ClusterTransportException;
use Infocyph\CacheLayer\Cluster\Transport\InvalidationTransportInterface;
use PDO;
use PDOException;

final readonly class PdoInvalidationTransport implements InvalidationTransportInterface
{
    private const string TABLE = 'cachelayer_invalidation_events';

    private string $driver;

    public function __construct(private PDO $connection)
    {
        $this->connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $driver = $connection->getAttribute(PDO::ATTR_DRIVER_NAME);
        $this->driver = is_string($driver) ? $driver : '';
        $this->createSchemaIfMissing();
    }

    public function consumeAfter(string $cluster, ?string $cursor, int $limit): InvalidationBatch
    {
        if ($limit < 1) {
            return new InvalidationBatch([]);
        }

        try {
            $statement = $this->connection->prepare($this->consumeSql($cursor));
            $statement->bindValue(':cluster', $cluster, PDO::PARAM_STR);
            if ($cursor !== null) {
                $statement->bindValue(':cursor', $this->eventId($cursor), PDO::PARAM_STR);
            }
            $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
            $statement->execute();
        } catch (PDOException $exception) {
            throw new ClusterTransportException('Unable to consume invalidation events.', 0, $exception);
        }

        $events = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $events[] = $this->eventFromRow($this->normalizeRow($row));
        }

        return new InvalidationBatch($events);
    }

    public function isCursorBefore(string $cursor, string $oldestAvailableId): bool
    {
        $cursor = $this->eventId($cursor);
        $oldestAvailableId = $this->eventId($oldestAvailableId);

        return strlen($cursor) === strlen($oldestAvailableId)
            ? strcmp($cursor, $oldestAvailableId) < 0
            : strlen($cursor) < strlen($oldestAvailableId);
    }

    public function oldestAvailableId(string $cluster): ?string
    {
        try {
            $statement = $this->connection->prepare(
                'SELECT MIN(event_id) FROM ' . self::TABLE . ' WHERE cluster_name = :cluster',
            );
            $statement->execute([':cluster' => $cluster]);
            $id = $statement->fetchColumn();
        } catch (PDOException $exception) {
            throw new ClusterTransportException('Unable to read the oldest invalidation event ID.', 0, $exception);
        }

        return is_int($id) || (is_string($id) && ctype_digit($id)) ? (string) $id : null;
    }

    public function pruneBefore(int $retentionBoundary, int $limit = 5_000): int
    {
        if ($limit < 1) {
            return 0;
        }

        try {
            $statement = $this->connection->prepare($this->pruneSql());
            $statement->bindValue(':boundary', $retentionBoundary, PDO::PARAM_INT);
            $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
            $statement->execute();
        } catch (PDOException $exception) {
            throw new ClusterTransportException('Unable to prune invalidation events.', 0, $exception);
        }

        return $statement->rowCount();
    }

    public function publish(InvalidationEvent $event): string
    {
        return $this->insert($this->connection, $event);
    }

    public function publishWithinTransaction(PDO $connection, InvalidationEvent $event): string
    {
        if ($connection !== $this->connection || !$connection->inTransaction()) {
            throw new ClusterTransportException(
                'Transactional outbox publishing requires this transport connection and an active transaction.',
            );
        }

        return $this->insert($connection, $event);
    }

    private function consumeSql(?string $cursor): string
    {
        $where = 'cluster_name = :cluster';
        if ($cursor !== null) {
            $where .= ' AND event_id > :cursor';
        }

        return 'SELECT event_id, cluster_name, namespace_name, event_type, identifier, origin_node_id, created_at '
            . 'FROM ' . self::TABLE . ' WHERE ' . $where . ' ORDER BY event_id ASC LIMIT :limit';
    }

    private function createClusterIndexIfMissing(): void
    {
        $ifNotExists = $this->driver === 'mysql' ? '' : ' IF NOT EXISTS';

        try {
            $this->connection->exec(
                'CREATE INDEX' . $ifNotExists . ' cachelayer_invalidation_events_cluster_idx '
                . 'ON ' . self::TABLE . ' (cluster_name, event_id)',
            );
        } catch (PDOException $exception) {
            if ($this->driver !== 'mysql' || !$this->isDuplicateIndex($exception)) {
                throw new ClusterTransportException('Unable to initialize the PDO invalidation transport index.', 0, $exception);
            }
        }
    }

    private function createSchemaIfMissing(): void
    {
        try {
            $this->connection->exec($this->createTableSql());
        } catch (PDOException $exception) {
            throw new ClusterTransportException('Unable to initialize the PDO invalidation transport schema.', 0, $exception);
        }

        $this->createClusterIndexIfMissing();
    }

    private function createTableSql(): string
    {
        $id = match ($this->driver) {
            'mysql' => 'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY',
            'pgsql' => 'BIGSERIAL PRIMARY KEY',
            default => 'INTEGER PRIMARY KEY AUTOINCREMENT',
        };

        return 'CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' ('
            . 'event_id ' . $id . ', cluster_name VARCHAR(128) NOT NULL, namespace_name VARCHAR(128) NOT NULL, '
            . 'event_type VARCHAR(32) NOT NULL, identifier VARCHAR(512) NULL, origin_node_id VARCHAR(255) NOT NULL, '
            . 'created_at BIGINT NOT NULL)';
    }

    /**
     * @param array $row The row argument.
     * @phpstan-param array<string, mixed> $row
     */
    private function eventFromRow(array $row): InvalidationEvent
    {
        $type = InvalidationEventType::tryFrom($this->requiredString($row, 'event_type'));
        if ($type === null) {
            throw new ClusterTransportException('Invalid invalidation event record returned by PDO transport.');
        }

        $identifier = $row['identifier'] ?? null;
        if ($identifier !== null && !is_string($identifier)) {
            throw new ClusterTransportException('Invalid invalidation event identifier returned by PDO transport.');
        }

        return new InvalidationEvent(
            $this->eventIdFromValue($row['event_id'] ?? null),
            $this->requiredString($row, 'cluster_name'),
            $this->requiredString($row, 'namespace_name'),
            $type,
            $identifier,
            $this->requiredString($row, 'origin_node_id'),
            $this->timestampFromValue($row['created_at'] ?? null),
        );
    }

    private function eventId(string $id): string
    {
        if (!ctype_digit($id)) {
            throw new ClusterTransportException('PDO invalidation transport requires unsigned numeric event IDs.');
        }

        $normalized = ltrim($id, '0');

        return $normalized === '' ? '0' : $normalized;
    }

    private function eventIdFromValue(mixed $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            return $value;
        }

        throw new ClusterTransportException('Invalid invalidation event ID returned by PDO transport.');
    }

    private function insert(PDO $connection, InvalidationEvent $event): string
    {
        try {
            $statement = $connection->prepare(
                'INSERT INTO ' . self::TABLE . ' '
                . '(cluster_name, namespace_name, event_type, identifier, origin_node_id, created_at) '
                . 'VALUES (:cluster, :namespace, :type, :identifier, :origin, :created_at)',
            );
            $statement->execute([
                ':cluster' => $event->cluster,
                ':namespace' => $event->namespace,
                ':type' => $event->type->value,
                ':identifier' => $event->identifier,
                ':origin' => $event->originNodeId,
                ':created_at' => $event->createdAt,
            ]);
            $id = $connection->lastInsertId();
        } catch (PDOException $exception) {
            throw new ClusterTransportException('Unable to publish an invalidation event.', 0, $exception);
        }

        if (!is_string($id) || $id === '') {
            throw new ClusterTransportException('PDO invalidation transport did not return an event ID.');
        }

        return $id;
    }

    private function isDuplicateIndex(PDOException $exception): bool
    {
        $errorInfo = $exception->errorInfo;

        return is_array($errorInfo) && ($errorInfo[1] ?? null) === 1061;
    }

    /**
     * @param array $row The row argument.
     * @phpstan-param array<mixed, mixed> $row
     * @phpstan-return array<string, mixed>
     */
    private function normalizeRow(array $row): array
    {
        $normalized = [];
        foreach ($row as $key => $value) {
            if (is_string($key)) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }

    private function pruneSql(): string
    {
        $selection = 'SELECT event_id FROM ' . self::TABLE . ' WHERE created_at < :boundary ORDER BY event_id LIMIT :limit';
        if ($this->driver === 'mysql') {
            $selection = 'SELECT event_id FROM (' . $selection . ') AS cachelayer_prunable_events';
        }

        return 'DELETE FROM ' . self::TABLE . ' WHERE event_id IN (' . $selection . ')';
    }

    private function requiredString(mixed $row, string $key): string
    {
        if (!is_array($row)) {
            throw new ClusterTransportException('Invalid PDO transport event row.');
        }

        $value = $row[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new ClusterTransportException("Invalid {$key} returned by PDO transport.");
        }

        return $value;
    }

    private function timestampFromValue(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        throw new ClusterTransportException('Invalid invalidation event timestamp returned by PDO transport.');
    }
}
