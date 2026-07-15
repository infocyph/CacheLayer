<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cluster\Consumer;

use Infocyph\CacheLayer\Cluster\Cursor\CursorStoreInterface;
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

    public function consume(int $limit = 1_000): int
    {
        if ($limit < 1) {
            return 0;
        }

        try {
            $recovered = $this->recovery->recoverIfRequired();
            $batch = $this->transport->consumeAfter($this->cluster, $this->cursorStore->current(), $limit);

            foreach ($batch->events as $event) {
                if ($event->originNodeId !== $this->nodeId) {
                    $this->handler->handle($event);
                }

                $this->cursorStore->advance((string) $event->id);
            }

            $count = count($batch->events);
            $this->status->recordConsume($count, $recovered);

            return $count;
        } catch (Throwable $exception) {
            $this->status->recordFailure($exception);

            throw $exception;
        }
    }
}
