<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cluster\Transport;

use Infocyph\CacheLayer\Cluster\Event\InvalidationBatch;
use Infocyph\CacheLayer\Cluster\Event\InvalidationEvent;

interface InvalidationTransportInterface
{
    public function consumeAfter(string $cluster, ?string $cursor, int $limit): InvalidationBatch;

    public function isCursorBefore(string $cursor, string $oldestAvailableId): bool;

    public function oldestAvailableId(string $cluster): ?string;

    public function publish(InvalidationEvent $event): string;
}
