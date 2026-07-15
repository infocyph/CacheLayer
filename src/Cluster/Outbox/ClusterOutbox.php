<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cluster\Outbox;

use Infocyph\CacheLayer\Cluster\Consumer\InvalidationHandler;
use Infocyph\CacheLayer\Cluster\Event\InvalidationEvent;
use Infocyph\CacheLayer\Cluster\Exception\ClusterCacheException;
use Infocyph\CacheLayer\Cluster\Transport\TransactionalInvalidationTransportInterface;
use PDO;

final class ClusterOutbox
{
    /** @var list<InvalidationEvent> */
    private array $events = [];

    private bool $localInvalidationApplied = false;

    public function __construct(
        private readonly PDO $connection,
        private readonly TransactionalInvalidationTransportInterface $transport,
        private readonly InvalidationHandler $handler,
        private readonly string $cluster,
        private readonly string $namespace,
        private readonly string $nodeId,
    ) {
        if (!$this->connection->inTransaction()) {
            throw new ClusterCacheException('Cluster outbox creation requires an active database transaction.');
        }
    }

    public function applyLocally(): void
    {
        if ($this->connection->inTransaction()) {
            throw new ClusterCacheException('Apply Cluster outbox local invalidation only after the transaction commits.');
        }
        if ($this->localInvalidationApplied) {
            return;
        }

        foreach ($this->events as $event) {
            $this->handler->handle($event);
        }

        $this->localInvalidationApplied = true;
    }

    public function clearNamespace(): void
    {
        $this->publish(InvalidationEvent::namespace($this->cluster, $this->namespace, $this->outboxOrigin()));
    }

    public function invalidateKey(string $key): void
    {
        $this->publish(InvalidationEvent::key($this->cluster, $this->namespace, $key, $this->outboxOrigin()));
    }

    public function invalidateTag(string $tag): void
    {
        $this->publish(InvalidationEvent::tag($this->cluster, $this->namespace, $tag, $this->outboxOrigin()));
    }

    /**
     * @param array $tags The tags argument.
     * @phpstan-param list<string> $tags
     */
    public function invalidateTags(array $tags): void
    {
        $seen = [];
        foreach ($tags as $tag) {
            if (isset($seen[$tag])) {
                continue;
            }

            $seen[$tag] = true;
            $this->invalidateTag($tag);
        }
    }

    private function outboxOrigin(): string
    {
        return $this->nodeId . ':outbox';
    }

    private function publish(InvalidationEvent $event): void
    {
        $this->transport->publishWithinTransaction($this->connection, $event);
        $this->events[] = $event;
    }
}
