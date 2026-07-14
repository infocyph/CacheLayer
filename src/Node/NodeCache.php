<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Node;

use Infocyph\CacheLayer\Cache\Adapter\ApcuCacheAdapter;
use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\CacheLayer\Cache\Lock\FileLockProvider;
use Infocyph\CacheLayer\Cache\Metrics\InMemoryCacheMetricsCollector;
use Infocyph\CacheLayer\Node\Adapter\NodeCacheAdapter;
use Infocyph\CacheLayer\Node\Adapter\NodeSqliteCacheAdapter;
use Infocyph\CacheLayer\Node\Connection\NodeSqliteConnection;
use Infocyph\CacheLayer\Node\Maintenance\NodeCacheMaintenance;
use Infocyph\CacheLayer\Node\Maintenance\NodeCachePruner;

final class NodeCache
{
    public static function create(NodeCacheConfig $config): Cache
    {
        $connection = NodeSqliteConnection::create($config);
        $metrics = new InMemoryCacheMetricsCollector();
        $adapter = new NodeCacheAdapter(
            self::createApcuAdapter($config),
            new NodeSqliteCacheAdapter($connection, $config->namespace),
            $config->failOpen,
            $metrics,
        );

        return new Cache(
            $adapter,
            new FileLockProvider($config->lockDirectory),
            $metrics,
        );
    }

    public static function maintenance(NodeCacheConfig $config): NodeCacheMaintenance
    {
        $connection = NodeSqliteConnection::create($config);
        $adapter = new NodeSqliteCacheAdapter($connection, $config->namespace);

        return new NodeCacheMaintenance(
            $adapter->connection(),
            new NodeCachePruner($adapter->connection(), $config->namespace),
        );
    }

    private static function createApcuAdapter(NodeCacheConfig $config): ?ApcuCacheAdapter
    {
        if (!$config->apcuEnabled || !extension_loaded('apcu') || !apcu_enabled()) {
            return null;
        }

        return new ApcuCacheAdapter($config->namespace);
    }
}
