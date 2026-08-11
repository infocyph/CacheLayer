<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cluster\Transport\Pdo;

use Infocyph\CacheLayer\Cluster\Event\InvalidationBatch;
use Infocyph\CacheLayer\Cluster\Event\InvalidationEvent;
use Infocyph\CacheLayer\Cluster\Event\InvalidationEventType;
use Infocyph\CacheLayer\Cluster\Exception\ClusterTransportException;
use Infocyph\CacheLayer\Cluster\Transport\InvalidationTransportData;
use Infocyph\CacheLayer\Cluster\Transport\InvalidationTransportInspectorInterface;
use Infocyph\CacheLayer\Cluster\Transport\TransactionalInvalidationTransportInterface;
use PDO;
use PDOException;

final readonly class PdoInvalidationTransport implements InvalidationTransportInspectorInterface, TransactionalInvalidationTransportInterface
{
    private const string TABLE = 'cachelayer_invalidation_events';

    private string $driver;

    public function __construct(
        private PDO $connection,
        bool $allowSqliteForTesting = false,
        bool $initializeSchema = true,
    ) {
        $this->connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->driver = PdoInvalidationSchema::validateConnection($connection, $allowSqliteForTesting);
        if ($initializeSchema) {
            PdoInvalidationSchema::install($connection, $allowSqliteForTesting);
        }
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
            $events[] = $this->eventFromRow(InvalidationTransportData::stringKeys($row));
        }

        return new InvalidationBatch($events);
    }

    public function countAfter(string $cluster, ?string $cursor): int
    {
        try {
            $sql = 'SELECT COUNT(*) FROM ' . self::TABLE . ' WHERE cluster_name = :cluster';
            if ($cursor !== null) {
                $sql .= ' AND event_id > :cursor';
            }
            $statement = $this->connection->prepare($sql);
            $statement->bindValue(':cluster', $cluster, PDO::PARAM_STR);
            if ($cursor !== null) {
                $statement->bindValue(':cursor', $this->eventId($cursor), PDO::PARAM_STR);
            }
            $statement->execute();
            $count = $statement->fetchColumn();
        } catch (PDOException $exception) {
            throw new ClusterTransportException('Unable to count pending invalidation events.', 0, $exception);
        }

        return is_numeric($count) ? (int) $count : 0;
    }

    public function isCursorBefore(string $cursor, string $oldestAvailableId): bool
    {
        $cursor = $this->eventId($cursor);
        $oldestAvailableId = $this->eventId($oldestAvailableId);

        return strlen($cursor) === strlen($oldestAvailableId)
            ? strcmp($cursor, $oldestAvailableId) < 0
            : strlen($cursor) < strlen($oldestAvailableId);
    }

    public function newestAvailableId(string $cluster): ?string
    {
        return $this->eventBoundary($cluster, 'MAX');
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

    private function eventBoundary(string $cluster, string $aggregate): ?string
    {
        try {
            $statement = $this->connection->prepare(
                'SELECT ' . $aggregate . '(event_id) FROM ' . self::TABLE . ' WHERE cluster_name = :cluster',
            );
            $statement->execute([':cluster' => $cluster]);
            $id = $statement->fetchColumn();
        } catch (PDOException $exception) {
            throw new ClusterTransportException('Unable to read invalidation event boundary.', 0, $exception);
        }

        return is_int($id) || (is_string($id) && ctype_digit($id)) ? (string) $id : null;
    }

    /**
     * @param array $row The row argument.
     * @phpstan-param array<string, mixed> $row
     */
    private function eventFromRow(array $row): InvalidationEvent
    {
        $type = InvalidationEventType::tryFrom(
            InvalidationTransportData::requiredString($row, 'event_type', 'PDO transport'),
        );
        if ($type === null) {
            throw new ClusterTransportException('Invalid invalidation event record returned by PDO transport.');
        }

        $identifier = $row['identifier'] ?? null;
        if ($identifier !== null && !is_string($identifier)) {
            throw new ClusterTransportException('Invalid invalidation event identifier returned by PDO transport.');
        }

        return new InvalidationEvent(
            $this->eventIdFromValue($row['event_id'] ?? null),
            InvalidationTransportData::requiredString($row, 'cluster_name', 'PDO transport'),
            InvalidationTransportData::requiredString($row, 'namespace_name', 'PDO transport'),
            $type,
            $identifier,
            InvalidationTransportData::requiredString($row, 'origin_node_id', 'PDO transport'),
            InvalidationTransportData::unsignedInteger(
                $row['created_at'] ?? null,
                'invalidation event timestamp',
                'PDO transport',
            ),
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

    private function pruneSql(): string
    {
        $selection = 'SELECT event_id FROM ' . self::TABLE . ' WHERE created_at < :boundary ORDER BY event_id LIMIT :limit';
        if ($this->driver === 'mysql') {
            $selection = 'SELECT event_id FROM (' . $selection . ') AS cachelayer_prunable_events';
        }

        return 'DELETE FROM ' . self::TABLE . ' WHERE event_id IN (' . $selection . ')';
    }
}
