<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cluster;

use Infocyph\CacheLayer\Cluster\Exception\ClusterConfigurationException;

final readonly class ClusterCacheConfig
{
    public function __construct(
        public string $cluster,
        public string $nodeId,
        public string $transportIdentity,
        public int $consumerBatchSize = 1_000,
        public bool $invalidateLocallyFirst = true,
    ) {
        ClusterInput::cluster($cluster);
        ClusterInput::nodeId($nodeId);
        ClusterInput::transportIdentity($transportIdentity);

        if ($consumerBatchSize < 1) {
            throw new ClusterConfigurationException('The consumer batch size must be greater than zero.');
        }
    }
}
