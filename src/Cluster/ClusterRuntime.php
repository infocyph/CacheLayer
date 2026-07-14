<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cluster;

use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\CacheLayer\Cluster\Consumer\InvalidationConsumer;
use Infocyph\CacheLayer\Cluster\Coordinator\ClusterCoordinator;
use Infocyph\CacheLayer\Cluster\Recovery\ClusterRecoveryManager;

final readonly class ClusterRuntime
{
    public function __construct(
        private Cache $cache,
        private ClusterCoordinator $coordinator,
        private InvalidationConsumer $consumer,
        private ClusterRecoveryManager $recovery,
        private int $consumerBatchSize,
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

    public function invalidateKey(string $key): void
    {
        $this->coordinator->invalidateKey($key);
    }

    public function invalidateTag(string $tag): void
    {
        $this->coordinator->invalidateTag($tag);
    }

    public function recoverIfRequired(): bool
    {
        return $this->recovery->recoverIfRequired();
    }
}
