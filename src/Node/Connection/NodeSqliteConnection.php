<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Node\Connection;

use Infocyph\CacheLayer\Node\Exception\NodeCacheConfigurationException;
use Infocyph\CacheLayer\Node\NodeCacheConfig;
use Infocyph\CacheLayer\Support\FilesystemTrust;
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

    private static function assertSecureDirectory(string $directory): void
    {
        if (!is_writable($directory)) {
            throw new NodeCacheConfigurationException("SQLite cache directory is not writable: {$directory}");
        }

        $permissions = fileperms($directory);
        if ($permissions !== false && (($permissions & 0x0002) === 0x0002)) {
            throw new NodeCacheConfigurationException("SQLite cache directory must not be world-writable: {$directory}");
        }
    }

    private static function assertSecureFile(string $file): void
    {
        if (!is_file($file)) {
            return;
        }

        $permissions = fileperms($file);
        if ($permissions !== false && (($permissions & 0x0002) === 0x0002)) {
            throw new NodeCacheConfigurationException("SQLite cache file must not be world-writable: {$file}");
        }
    }

    private static function assertTrustedFilePath(string $file): void
    {
        if (FilesystemTrust::containsSymlink($file)) {
            throw new NodeCacheConfigurationException("Refusing symlinked SQLite cache path: {$file}");
        }
        if (file_exists($file) && !is_file($file)) {
            throw new NodeCacheConfigurationException("SQLite cache file path is not a regular file: {$file}");
        }
    }

    private static function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new NodeCacheConfigurationException("Unable to create SQLite cache directory: {$directory}");
        }
    }

    private static function prepareDirectory(string $file): void
    {
        self::assertTrustedFilePath($file);

        $directory = dirname($file);
        self::ensureDirectory($directory);
        self::assertTrustedFilePath($file);
        self::assertSecureDirectory($directory);
        self::assertSecureFile($file);
    }
}
