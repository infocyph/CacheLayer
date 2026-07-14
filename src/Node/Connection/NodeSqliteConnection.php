<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Node\Connection;

use Infocyph\CacheLayer\Node\Exception\NodeCacheConfigurationException;
use Infocyph\CacheLayer\Node\NodeCacheConfig;
use PDO;
use PDOException;

final class NodeSqliteConnection
{
    public static function create(NodeCacheConfig $config): PDO
    {
        self::prepareDirectory($config->sqliteFile);

        try {
            $connection = new PDO(
                'sqlite:' . $config->sqliteFile,
                null,
                null,
                [
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_STRINGIFY_FETCHES => false,
                ],
            );
            $connection->exec('PRAGMA journal_mode = WAL');
            $connection->exec('PRAGMA synchronous = NORMAL');
            $connection->exec('PRAGMA busy_timeout = ' . $config->busyTimeoutMs);
            $connection->exec('PRAGMA temp_store = MEMORY');
        } catch (PDOException $exception) {
            throw new NodeCacheConfigurationException('Unable to initialize the node SQLite cache.', 0, $exception);
        }

        return $connection;
    }

    private static function prepareDirectory(string $file): void
    {
        if (is_link($file)) {
            throw new NodeCacheConfigurationException("Refusing symlinked SQLite cache file: {$file}");
        }

        $directory = dirname($file);
        if (is_link($directory)) {
            throw new NodeCacheConfigurationException("Refusing symlinked SQLite cache directory: {$directory}");
        }

        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new NodeCacheConfigurationException("Unable to create SQLite cache directory: {$directory}");
        }

        if (!is_writable($directory)) {
            throw new NodeCacheConfigurationException("SQLite cache directory is not writable: {$directory}");
        }

        $permissions = fileperms($directory);
        if ($permissions !== false && (($permissions & 0x0002) === 0x0002)) {
            throw new NodeCacheConfigurationException("SQLite cache directory must not be world-writable: {$directory}");
        }
    }
}
