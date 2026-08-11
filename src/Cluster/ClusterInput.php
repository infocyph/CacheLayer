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
    public static function cluster(string $cluster): string
    {
        self::boundedName($cluster, 128, 'cluster name', ClusterConfigurationException::class);

        return $cluster;
    }

    public static function eventId(string $eventId): string
    {
        if (strlen($eventId) < 1 || strlen($eventId) > 128 || preg_match('/^[0-9-]+$/D', $eventId) !== 1) {
            throw new ClusterCacheException('Cluster event IDs must be 1-128 numeric or hyphen characters.');
        }

        return $eventId;
    }

    public static function identifier(InvalidationEventType $type, ?string $identifier): ?string
    {
        if ($type === InvalidationEventType::Namespace) {
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

    public static function namespace(string $namespace): string
    {
        try {
            return CacheInput::namespace($namespace);
        } catch (CacheInvalidArgumentException $exception) {
            throw new ClusterConfigurationException($exception->getMessage(), 0, $exception);
        }
    }

    public static function nodeId(string $nodeId): string
    {
        self::boundedName($nodeId, 255, 'node ID', ClusterConfigurationException::class);

        return $nodeId;
    }

    /** @param class-string<ClusterCacheException> $exceptionClass */
    private static function boundedName(string $value, int $maximum, string $label, string $exceptionClass): void
    {
        if (strlen($value) < 1
            || strlen($value) > $maximum
            || preg_match('/^[A-Za-z0-9_.-]+$/D', $value) !== 1) {
            throw new $exceptionClass(sprintf(
                'The %s must contain 1-%d characters from A-Z, a-z, 0-9, _, ., and -.',
                $label,
                $maximum,
            ));
        }
    }
}
