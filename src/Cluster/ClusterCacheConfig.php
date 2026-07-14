<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cluster;

use Infocyph\CacheLayer\Cluster\Exception\ClusterConfigurationException;

final readonly class ClusterCacheConfig
{
    public function __construct(
        public string $cluster,
        public string $nodeId,
        public int $consumerBatchSize = 1_000,
        public bool $invalidateLocallyFirst = true,
    ) {
        if (trim($cluster) === '') {
            throw new ClusterConfigurationException('The cluster name cannot be empty.');
        }

        if (trim($nodeId) === '') {
            throw new ClusterConfigurationException('The node ID cannot be empty.');
        }

        if ($consumerBatchSize < 1) {
            throw new ClusterConfigurationException('The consumer batch size must be greater than zero.');
        }
    }
}
