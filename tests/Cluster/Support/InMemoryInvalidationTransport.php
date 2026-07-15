<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Tests\Cluster\Support;

use Infocyph\CacheLayer\Cluster\Event\InvalidationBatch;
use Infocyph\CacheLayer\Cluster\Event\InvalidationEvent;
use Infocyph\CacheLayer\Cluster\Transport\InvalidationTransportInspectorInterface;

final class InMemoryInvalidationTransport implements InvalidationTransportInspectorInterface
{
    /** @var array<string, list<InvalidationEvent>> */
    private array $events = [];

    private int $nextId = 1;

    public function consumeAfter(string $cluster, ?string $cursor, int $limit): InvalidationBatch
    {
        $after = $cursor === null ? 0 : (int) $cursor;
        $events = array_filter(
            $this->events[$cluster] ?? [],
            static fn(InvalidationEvent $event): bool => (int) $event->id > $after,
        );

        return new InvalidationBatch(array_values(array_slice($events, 0, $limit)));
    }

    public function discardBefore(string $cluster, int $eventId): void
    {
        $this->events[$cluster] = array_values(array_filter(
            $this->events[$cluster] ?? [],
            static fn(InvalidationEvent $event): bool => (int) $event->id >= $eventId,
        ));
    }

    public function isCursorBefore(string $cursor, string $oldestAvailableId): bool
    {
        return (int) $cursor < (int) $oldestAvailableId;
    }

    public function oldestAvailableId(string $cluster): ?string
    {
        return $this->events[$cluster][0]->id ?? null;
    }

    public function newestAvailableId(string $cluster): ?string
    {
        $events = $this->events[$cluster] ?? [];

        return $events === [] ? null : $events[array_key_last($events)]->id;
    }

    public function countAfter(string $cluster, ?string $cursor): int
    {
        return count($this->consumeAfter($cluster, $cursor, PHP_INT_MAX)->events);
    }

    public function publish(InvalidationEvent $event): string
    {
        $id = (string) $this->nextId++;
        $this->events[$event->cluster][] = $event->withId($id);

        return $id;
    }
}
