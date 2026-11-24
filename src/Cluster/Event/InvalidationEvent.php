<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cluster\Event;

use Infocyph\CacheLayer\Cluster\ClusterInput;
use Infocyph\CacheLayer\Cluster\Exception\ClusterCacheException;

final readonly class InvalidationEvent
{
    /** @throws ClusterCacheException */
    public function __construct(
        public ?string $id,
        public string $cluster,
        public string $namespace,
        public InvalidationEventType $type,
        public ?string $identifier,
        public string $originNodeId,
        public int $createdAt,
    ) {
        if ($id !== null) {
            ClusterInput::eventId($id);
        }
        ClusterInput::cluster($cluster);
        ClusterInput::namespace($namespace);
        ClusterInput::nodeId($originNodeId);
        ClusterInput::identifier($type, $identifier);

        if ($createdAt < 0) {
            throw new ClusterCacheException('Invalidation event timestamps cannot be negative.');
        }
    }

    /** @throws ClusterCacheException */
    public static function key(string $cluster, string $namespace, string $key, string $originNodeId): self
    {
        return new self(null, $cluster, $namespace, InvalidationEventType::Key, $key, $originNodeId, time());
    }

    /** @throws ClusterCacheException */
    public static function namespace(string $cluster, string $namespace, string $originNodeId): self
    {
        return new self(null, $cluster, $namespace, InvalidationEventType::Namespace, null, $originNodeId, time());
    }

    /** @throws ClusterCacheException */
    public static function tag(string $cluster, string $namespace, string $tag, string $originNodeId): self
    {
        return new self(null, $cluster, $namespace, InvalidationEventType::Tag, $tag, $originNodeId, time());
    }

    /** @throws ClusterCacheException */
    public function withId(string $id): self
    {
        return new self($id, $this->cluster, $this->namespace, $this->type, $this->identifier, $this->originNodeId, $this->createdAt);
    }
}
