<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cluster\Consumer;

use Infocyph\CacheLayer\Cluster\Cursor\CursorStoreInterface;
use Infocyph\CacheLayer\Cluster\Event\InvalidationEvent;
use Infocyph\CacheLayer\Cluster\Exception\ClusterTransportException;
use Infocyph\CacheLayer\Cluster\Health\ClusterStatusTracker;
use Infocyph\CacheLayer\Cluster\Recovery\ClusterRecoveryManager;
use Infocyph\CacheLayer\Cluster\Transport\InvalidationTransportInterface;
use Throwable;

final readonly class InvalidationConsumer
{
    public function __construct(
        private InvalidationTransportInterface $transport,
        private CursorStoreInterface $cursorStore,
        private InvalidationHandler $handler,
        private ClusterRecoveryManager $recovery,
        private string $cluster,
        private string $nodeId,
        private ClusterStatusTracker $status,
    ) {}

    /**
     * @throws Throwable
     */
    public function consume(int $limit = 1_000): int
    {
        if ($limit < 1) {
            return 0;
        }

        try {
            $recovered = $this->recovery->recoverIfRequired();
            $cursor = $this->cursorStore->current();
            $batch = $this->transport->consumeAfter($this->cluster, $cursor, $limit);
            $count = count($batch->events);
            if ($count > $limit) {
                throw new ClusterTransportException('Invalidation transport exceeded the requested batch limit.');
            }

            foreach ($batch->events as $event) {
                $id = $this->validateEvent($event, $cursor);
                if ($event->originNodeId !== $this->nodeId) {
                    $this->handler->handle($event);
                }

                $this->cursorStore->advance($id);
                $cursor = $id;
            }

            $this->status->recordConsume($count, $recovered);

            return $count;
        } catch (Throwable $exception) {
            $this->status->recordFailure($exception);

            throw $exception;
        }
    }

    /** @throws ClusterTransportException */
    private function validateEvent(InvalidationEvent $event, ?string $cursor): string
    {
        $id = $event->id;
        if ($id === null || $event->cluster !== $this->cluster) {
            throw new ClusterTransportException('Invalidation transport returned an event outside the requested cluster or without an ID.');
        }
        if ($cursor !== null && !$this->transport->isCursorBefore($cursor, $id)) {
            throw new ClusterTransportException('Invalidation transport returned a duplicate or out-of-order event.');
        }

        return $id;
    }
}
