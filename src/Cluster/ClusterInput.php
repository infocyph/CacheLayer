<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cluster;

use Infocyph\CacheLayer\Cache\CacheInput;
use Infocyph\CacheLayer\Cluster\Event\InvalidationEventType;
use Infocyph\CacheLayer\Cluster\Exception\ClusterCacheException;
use Infocyph\CacheLayer\Cluster\Exception\ClusterConfigurationException;
use Infocyph\CacheLayer\Exceptions\CacheInvalidArgumentException;

/** @internal */
final class ClusterInput
{
    /** @throws ClusterConfigurationException */
    public static function cluster(string $cluster): string
    {
        self::boundedName($cluster, 128, 'cluster name');

        return $cluster;
    }

    /** @throws ClusterCacheException */
    public static function eventId(string $eventId): string
    {
        if (strlen($eventId) < 1 || strlen($eventId) > 128 || preg_match('/^[0-9-]+$/D', $eventId) !== 1) {
            throw new ClusterCacheException('Cluster event IDs must be 1-128 numeric or hyphen characters.');
        }

        return $eventId;
    }

    /** @throws ClusterCacheException */
    public static function identifier(InvalidationEventType $type, ?string $identifier): ?string
    {
        if ($type === InvalidationEventType::NamespaceClear) {
            if ($identifier !== null) {
                throw new ClusterCacheException('Namespace invalidation events must not contain an identifier.');
            }

            return null;
        }
        if ($identifier === null) {
            throw new ClusterCacheException('Key and tag invalidation events require an identifier.');
        }

        try {
            if ($type === InvalidationEventType::Key) {
                CacheInput::key($identifier);
            } else {
                CacheInput::tags([$identifier]);
            }
        } catch (CacheInvalidArgumentException $exception) {
            throw new ClusterCacheException($exception->getMessage(), 0, $exception);
        }

        return $identifier;
    }

    /** @throws ClusterConfigurationException */
    public static function namespace(string $namespace): string
    {
        try {
            return CacheInput::namespace($namespace);
        } catch (CacheInvalidArgumentException $exception) {
            throw new ClusterConfigurationException($exception->getMessage(), 0, $exception);
        }
    }

    /** @throws ClusterConfigurationException */
    public static function nodeId(string $nodeId): string
    {
        self::boundedName($nodeId, 255, 'node ID');

        return $nodeId;
    }

    /** @throws ClusterConfigurationException */
    public static function transportIdentity(string $transportIdentity): string
    {
        self::boundedName($transportIdentity, 128, 'transport identity');

        return $transportIdentity;
    }

    /** @throws ClusterConfigurationException */
    private static function boundedName(string $value, int $maximum, string $label): void
    {
        if (strlen($value) < 1
            || strlen($value) > $maximum
            || preg_match('/^[A-Za-z0-9_.-]+$/D', $value) !== 1) {
            throw new ClusterConfigurationException(sprintf(
                'The %s must contain 1-%d characters from A-Z, a-z, 0-9, _, ., and -.',
                $label,
                $maximum,
            ));
        }
    }
}
