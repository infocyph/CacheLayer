<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cluster\Transport;

use Infocyph\CacheLayer\Cluster\Event\InvalidationBatch;
use Infocyph\CacheLayer\Cluster\Event\InvalidationEvent;
use Infocyph\CacheLayer\Cluster\Event\InvalidationEventType;
use Infocyph\CacheLayer\Cluster\Exception\ClusterTransportException;

final readonly class RedisStreamInvalidationTransport implements InvalidationTransportInterface
{
    public function __construct(
        private \Redis $client,
        private string $prefix = 'cachelayer:invalidation:',
        private int $maxLength = 100_000,
    ) {
        if (!class_exists(\Redis::class)) {
            throw new ClusterTransportException('phpredis extension not loaded');
        }
        if ($prefix === '' || $maxLength < 1) {
            throw new ClusterTransportException('Redis Stream transport requires a prefix and positive maximum length.');
        }
    }

    public function consumeAfter(string $cluster, ?string $cursor, int $limit): InvalidationBatch
    {
        if ($limit < 1) {
            return new InvalidationBatch([]);
        }

        try {
            $streams = $this->client->xRead([$this->stream($cluster) => $cursor ?? '0-0'], $limit);
        } catch (\RedisException $exception) {
            throw new ClusterTransportException('Unable to consume Redis Stream invalidation events.', 0, $exception);
        }

        if (!is_array($streams)) {
            return new InvalidationBatch([]);
        }

        $entries = $streams[$this->stream($cluster)] ?? null;
        if (!is_array($entries)) {
            return new InvalidationBatch([]);
        }

        $events = [];
        foreach ($entries as $id => $fields) {
            if (!is_string($id) || !is_array($fields)) {
                continue;
            }
            $events[] = $this->eventFromFields($id, $fields);
        }

        return new InvalidationBatch($events);
    }

    public function isCursorBefore(string $cursor, string $oldestAvailableId): bool
    {
        return $this->compareIds($cursor, $oldestAvailableId) < 0;
    }

    public function oldestAvailableId(string $cluster): ?string
    {
        return $this->boundary($cluster, false);
    }

    public function publish(InvalidationEvent $event): string
    {
        try {
            $id = $this->client->xAdd(
                $this->stream($event->cluster),
                '*',
                [
                    'cluster' => $event->cluster,
                    'namespace' => $event->namespace,
                    'type' => $event->type->value,
                    'identifier' => $event->identifier ?? '',
                    'has_identifier' => $event->identifier === null ? '0' : '1',
                    'origin' => $event->originNodeId,
                    'created_at' => (string) $event->createdAt,
                ],
                $this->maxLength,
                true,
            );
        } catch (\RedisException $exception) {
            throw new ClusterTransportException('Unable to publish Redis Stream invalidation event.', 0, $exception);
        }

        if (!is_string($id) || $id === '') {
            throw new ClusterTransportException('Redis Stream transport did not return an event ID.');
        }

        return $id;
    }

    private function boundary(string $cluster, bool $newest): ?string
    {
        try {
            $entries = $newest
                ? $this->client->xRevRange($this->stream($cluster), '+', '-', 1)
                : $this->client->xRange($this->stream($cluster), '-', '+', 1);
        } catch (\RedisException $exception) {
            throw new ClusterTransportException('Unable to read Redis Stream invalidation boundary.', 0, $exception);
        }

        if (!is_array($entries) || $entries === []) {
            return null;
        }

        $id = array_key_first($entries);

        return is_string($id) ? $id : null;
    }

    private function clusterFromStreamEvent(mixed $fields): string
    {
        return $this->field($fields, 'cluster');
    }

    private function compareIds(string $left, string $right): int
    {
        [$leftMilliseconds, $leftSequence] = $this->idParts($left);
        [$rightMilliseconds, $rightSequence] = $this->idParts($right);

        return $leftMilliseconds === $rightMilliseconds
            ? $leftSequence <=> $rightSequence
            : $leftMilliseconds <=> $rightMilliseconds;
    }

    /**
     * @param string $id The ID argument.
     * @param array $fields The fields argument.
     * @phpstan-param array<mixed, mixed> $fields
     */
    private function eventFromFields(string $id, array $fields): InvalidationEvent
    {
        $type = InvalidationEventType::tryFrom($this->field($fields, 'type'));
        if ($type === null) {
            throw new ClusterTransportException('Redis Stream event has an invalid event type.');
        }

        $identifier = $this->field($fields, 'has_identifier') === '1'
            ? $this->field($fields, 'identifier')
            : null;

        return new InvalidationEvent(
            $id,
            $this->clusterFromStreamEvent($fields),
            $this->field($fields, 'namespace'),
            $type,
            $identifier,
            $this->field($fields, 'origin'),
            (int) $this->field($fields, 'created_at'),
        );
    }

    private function field(mixed $fields, string $name): string
    {
        if (!is_array($fields)) {
            throw new ClusterTransportException('Redis Stream event fields must be an array.');
        }

        $value = $fields[$name] ?? null;
        if (!is_string($value) || $value === '') {
            throw new ClusterTransportException("Redis Stream event has an invalid {$name} field.");
        }

        return $value;
    }

    /**
     * @param string $id The ID argument.
     * @return array The ID parts.
     * @phpstan-return array{int, int}
     */
    private function idParts(string $id): array
    {
        $parts = explode('-', $id, 2);
        if (count($parts) !== 2 || !ctype_digit($parts[0]) || !ctype_digit($parts[1])) {
            throw new ClusterTransportException('Redis Stream event IDs must have the form milliseconds-sequence.');
        }

        return [(int) $parts[0], (int) $parts[1]];
    }

    private function stream(string $cluster): string
    {
        if ($cluster === '') {
            throw new ClusterTransportException('Redis Stream cluster name cannot be empty.');
        }

        return $this->prefix . $cluster;
    }
}
