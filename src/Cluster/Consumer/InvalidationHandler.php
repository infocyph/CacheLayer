<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cluster\Consumer;

use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\CacheLayer\Cluster\Event\InvalidationEvent;
use Infocyph\CacheLayer\Cluster\Event\InvalidationEventType;
use Infocyph\CacheLayer\Cluster\Exception\ClusterCacheException;

final readonly class InvalidationHandler
{
    public function __construct(
        private Cache $cache,
        private string $namespace,
    ) {}

    public function handle(InvalidationEvent $event): void
    {
        if ($event->namespace !== $this->namespace) {
            return;
        }

        $handled = match ($event->type) {
            InvalidationEventType::Key => $this->invalidateKey($event),
            InvalidationEventType::Namespace => $this->cache->clear(),
            InvalidationEventType::Tag => $this->invalidateTag($event),
        };
        if (!$handled) {
            throw new ClusterCacheException('Unable to apply cluster invalidation to the local cache.');
        }
    }

    private function invalidateKey(InvalidationEvent $event): bool
    {
        return $event->identifier !== null && $this->cache->delete($event->identifier);
    }

    private function invalidateTag(InvalidationEvent $event): bool
    {
        return $event->identifier !== null && $this->cache->invalidateTag($event->identifier);
    }
}
