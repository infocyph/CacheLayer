<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cluster\Event;

use Infocyph\CacheLayer\Cluster\Exception\ClusterCacheException;

final readonly class InvalidationBatch
{
    /**
     * @param array $events The events argument.
     * @phpstan-param list<InvalidationEvent> $events
     */
    public function __construct(public array $events)
    {
        foreach ($events as $event) {
            if ($event->id === null || $event->id === '') {
                throw new ClusterCacheException('Consumed invalidation events require a transport-assigned ID.');
            }
        }
    }
}
