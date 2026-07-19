<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cluster\Event;

use Infocyph\CacheLayer\Cluster\Exception\ClusterCacheException;

final readonly class InvalidationEvent
{
    public function __construct(
        public ?string $id,
        public string $cluster,
        public string $namespace,
        public InvalidationEventType $type,
        public ?string $identifier,
        public string $originNodeId,
        public int $createdAt,
    ) {
        if ($cluster === '' || $namespace === '' || $originNodeId === '') {
            throw new ClusterCacheException('Invalidation events require a cluster, namespace, and origin node ID.');
        }

        if ($type === InvalidationEventType::Namespace && $identifier !== null) {
            throw new ClusterCacheException('Namespace invalidation events must not contain an identifier.');
        }

        if ($type !== InvalidationEventType::Namespace && ($identifier === null || $identifier === '')) {
            throw new ClusterCacheException('Key and tag invalidation events require a non-empty identifier.');
        }

        if ($createdAt < 0) {
            throw new ClusterCacheException('Invalidation event timestamps cannot be negative.');
        }
    }

    public static function key(string $cluster, string $namespace, string $key, string $originNodeId): self
    {
        return new self(null, $cluster, $namespace, InvalidationEventType::Key, $key, $originNodeId, time());
    }

    public static function namespace(string $cluster, string $namespace, string $originNodeId): self
    {
        return new self(null, $cluster, $namespace, InvalidationEventType::Namespace, null, $originNodeId, time());
    }

    public static function tag(string $cluster, string $namespace, string $tag, string $originNodeId): self
    {
        return new self(null, $cluster, $namespace, InvalidationEventType::Tag, $tag, $originNodeId, time());
    }

    public function withId(string $id): self
    {
        if ($id === '') {
            throw new ClusterCacheException('Invalidation event IDs cannot be empty.');
        }

        return new self($id, $this->cluster, $this->namespace, $this->type, $this->identifier, $this->originNodeId, $this->createdAt);
    }
}
