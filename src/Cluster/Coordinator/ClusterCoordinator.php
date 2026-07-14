<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cluster\Coordinator;

use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\CacheLayer\Cluster\Event\InvalidationEvent;
use Infocyph\CacheLayer\Cluster\Exception\ClusterCacheException;
use Infocyph\CacheLayer\Cluster\Transport\InvalidationTransportInterface;

final readonly class ClusterCoordinator
{
    public function __construct(
        private Cache $cache,
        private string $cluster,
        private string $namespace,
        private string $nodeId,
        private InvalidationTransportInterface $transport,
        private bool $invalidateLocallyFirst = true,
    ) {}

    public function clearNamespace(): void
    {
        $event = InvalidationEvent::namespace($this->cluster, $this->namespace, $this->nodeId);
        $this->coordinate($event, fn(): bool => $this->cache->clear());
    }

    public function invalidateKey(string $key): void
    {
        $event = InvalidationEvent::key($this->cluster, $this->namespace, $key, $this->nodeId);
        $this->coordinate($event, fn(): bool => $this->cache->delete($key));
    }

    public function invalidateTag(string $tag): void
    {
        $event = InvalidationEvent::tag($this->cluster, $this->namespace, $tag, $this->nodeId);
        $this->coordinate($event, fn(): bool => $this->cache->invalidateTag($tag));
    }

    private function coordinate(InvalidationEvent $event, callable $invalidate): void
    {
        if ($this->invalidateLocallyFirst) {
            $this->invalidate($invalidate);
            $this->transport->publish($event);

            return;
        }

        $this->transport->publish($event);
        $this->invalidate($invalidate);
    }

    private function invalidate(callable $invalidate): void
    {
        if (!$invalidate()) {
            throw new ClusterCacheException('Unable to invalidate the local node cache.');
        }
    }
}
