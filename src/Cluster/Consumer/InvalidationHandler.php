<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cluster\Consumer;

use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\CacheLayer\Cluster\Event\InvalidationEvent;
use Infocyph\CacheLayer\Cluster\Event\InvalidationEventType;

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

        match ($event->type) {
            InvalidationEventType::Key => $this->invalidateKey($event),
            InvalidationEventType::Namespace => $this->cache->clear(),
            InvalidationEventType::Tag => $this->invalidateTag($event),
        };
    }

    private function invalidateKey(InvalidationEvent $event): void
    {
        if ($event->identifier !== null) {
            $this->cache->delete($event->identifier);
        }
    }

    private function invalidateTag(InvalidationEvent $event): void
    {
        if ($event->identifier !== null) {
            $this->cache->invalidateTag($event->identifier);
        }
    }
}
