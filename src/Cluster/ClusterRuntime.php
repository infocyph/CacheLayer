<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cluster;

use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\CacheLayer\Cluster\Consumer\InvalidationConsumer;
use Infocyph\CacheLayer\Cluster\Consumer\InvalidationHandler;
use Infocyph\CacheLayer\Cluster\Coordinator\ClusterCoordinator;
use Infocyph\CacheLayer\Cluster\Cursor\CursorStoreInterface;
use Infocyph\CacheLayer\Cluster\Exception\ClusterCacheException;
use Infocyph\CacheLayer\Cluster\Health\ClusterStatus;
use Infocyph\CacheLayer\Cluster\Health\ClusterStatusTracker;
use Infocyph\CacheLayer\Cluster\Outbox\ClusterOutbox;
use Infocyph\CacheLayer\Cluster\Recovery\ClusterRecoveryManager;
use Infocyph\CacheLayer\Cluster\Transport\InvalidationTransportInspectorInterface;
use Infocyph\CacheLayer\Cluster\Transport\InvalidationTransportInterface;
use Infocyph\CacheLayer\Cluster\Transport\TransactionalInvalidationTransportInterface;
use PDO;

final readonly class ClusterRuntime
{
    public function __construct(
        private Cache $cache,
        private ClusterCoordinator $coordinator,
        private InvalidationConsumer $consumer,
        private ClusterRecoveryManager $recovery,
        private int $consumerBatchSize,
        private CursorStoreInterface $cursorStore,
        private InvalidationTransportInterface $transport,
        private ClusterStatusTracker $status,
        private string $cluster,
        private string $nodeId,
        private string $namespace,
    ) {}

    public function cache(): Cache
    {
        return $this->cache;
    }

    public function clearNamespace(): void
    {
        $this->coordinator->clearNamespace();
    }

    public function consume(?int $limit = null): int
    {
        return $this->consumer->consume($limit ?? $this->consumerBatchSize);
    }

    public function drain(?int $limit = null, int $maxBatches = 100): int
    {
        $limit ??= $this->consumerBatchSize;
        if ($limit < 1 || $maxBatches < 1) {
            throw new ClusterCacheException('Cluster drain limit and maximum batches must be greater than zero.');
        }

        $total = 0;
        for ($batch = 0; $batch < $maxBatches; ++$batch) {
            $processed = $this->consumer->consume($limit);
            $total += $processed;
            if ($processed < $limit) {
                break;
            }
        }

        return $total;
    }

    public function invalidateKey(string $key): void
    {
        $this->coordinator->invalidateKey($key);
    }

    public function invalidateTag(string $tag): void
    {
        $this->coordinator->invalidateTag($tag);
    }

    /**
     * @param array $tags The tags argument.
     * @phpstan-param list<string> $tags
     */
    public function invalidateTags(array $tags): void
    {
        $this->coordinator->invalidateTags($tags);
    }

    public function outbox(PDO $connection): ClusterOutbox
    {
        if (!$this->transport instanceof TransactionalInvalidationTransportInterface) {
            throw new ClusterCacheException('The configured Cluster Cache transport does not support transactional invalidation.');
        }

        return new ClusterOutbox(
            $connection,
            $this->transport,
            new InvalidationHandler($this->cache, $this->namespace),
            $this->cluster,
            $this->namespace,
            $this->nodeId,
        );
    }

    public function recoverIfRequired(): bool
    {
        $recovered = $this->recovery->recoverIfRequired();
        if ($recovered) {
            $this->status->recordRecovery();
        }

        return $recovered;
    }

    public function status(): ClusterStatus
    {
        $cursor = $this->cursorStore->current();
        $oldest = $this->transport->oldestAvailableId($this->cluster);
        $newest = null;
        $pending = null;
        if ($this->transport instanceof InvalidationTransportInspectorInterface) {
            $newest = $this->transport->newestAvailableId($this->cluster);
            $pending = $this->transport->countAfter($this->cluster, $cursor);
        }

        return $this->status->snapshot(
            $this->cluster,
            $this->nodeId,
            $cursor,
            $this->cursorStore->updatedAt(),
            $oldest,
            $newest,
            $pending,
        );
    }
}
