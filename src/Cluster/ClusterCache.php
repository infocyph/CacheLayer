<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cluster;

use Infocyph\CacheLayer\Cluster\Consumer\InvalidationConsumer;
use Infocyph\CacheLayer\Cluster\Consumer\InvalidationHandler;
use Infocyph\CacheLayer\Cluster\Coordinator\ClusterCoordinator;
use Infocyph\CacheLayer\Cluster\Cursor\SqliteCursorStore;
use Infocyph\CacheLayer\Cluster\Health\ClusterStatusTracker;
use Infocyph\CacheLayer\Cluster\Recovery\ClusterRecoveryManager;
use Infocyph\CacheLayer\Cluster\Transport\InvalidationTransportInterface;
use Infocyph\CacheLayer\Node\NodeCache;
use Infocyph\CacheLayer\Node\NodeCacheConfig;

final class ClusterCache
{
    public static function create(
        NodeCacheConfig $node,
        ClusterCacheConfig $cluster,
        InvalidationTransportInterface $transport,
    ): ClusterRuntime {
        $cache = NodeCache::create($node);
        $cursorStore = new SqliteCursorStore($node->sqliteFile, $cluster->cluster, $cluster->nodeId);
        $status = new ClusterStatusTracker();
        $recovery = new ClusterRecoveryManager($cache, $cursorStore, $transport, $cluster->cluster);
        $coordinator = new ClusterCoordinator(
            $cache,
            $cluster->cluster,
            $node->namespace,
            $cluster->nodeId,
            $transport,
            $cluster->invalidateLocallyFirst,
        );
        $consumer = new InvalidationConsumer(
            $transport,
            $cursorStore,
            new InvalidationHandler($cache, $node->namespace),
            $recovery,
            $cluster->cluster,
            $cluster->nodeId,
            $status,
        );

        return new ClusterRuntime(
            $cache,
            $coordinator,
            $consumer,
            $recovery,
            $cluster->consumerBatchSize,
            $cursorStore,
            $transport,
            $status,
            $cluster->cluster,
            $cluster->nodeId,
            $node->namespace,
        );
    }
}
