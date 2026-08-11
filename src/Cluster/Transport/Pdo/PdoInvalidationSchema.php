<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cluster\Transport\Pdo;

use Infocyph\CacheLayer\Cluster\Exception\ClusterTransportException;
use PDO;
use PDOException;

final class PdoInvalidationSchema
{
    private const string TABLE = 'cachelayer_invalidation_events';

    public static function install(PDO $connection, bool $allowSqliteForTesting = false): void
    {
        $driver = self::driver($connection, $allowSqliteForTesting);

        try {
            $connection->exec(self::createTableSql($driver));
            self::createIndex($connection, $driver);
        } catch (PDOException $exception) {
            throw new ClusterTransportException('Unable to initialize the PDO invalidation transport schema.', 0, $exception);
        }
    }

    public static function validateConnection(PDO $connection, bool $allowSqliteForTesting = false): string
    {
        return self::driver($connection, $allowSqliteForTesting);
    }

    private static function createIndex(PDO $connection, string $driver): void
    {
        $ifNotExists = $driver === 'mysql' ? '' : ' IF NOT EXISTS';

        try {
            $connection->exec(
                'CREATE INDEX' . $ifNotExists . ' cachelayer_invalidation_events_cluster_idx '
                . 'ON ' . self::TABLE . ' (cluster_name, event_id)',
            );
        } catch (PDOException $exception) {
            $duplicate = is_array($exception->errorInfo) && ($exception->errorInfo[1] ?? null) === 1061;
            if ($driver !== 'mysql' || !$duplicate) {
                throw $exception;
            }
        }
    }

    private static function createTableSql(string $driver): string
    {
        $id = match ($driver) {
            'mysql' => 'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY',
            'pgsql' => 'BIGSERIAL PRIMARY KEY',
            default => 'INTEGER PRIMARY KEY AUTOINCREMENT',
        };

        return 'CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' ('
            . 'event_id ' . $id . ', cluster_name VARCHAR(128) NOT NULL, namespace_name VARCHAR(64) NOT NULL, '
            . 'event_type VARCHAR(32) NOT NULL, identifier VARCHAR(64) NULL, origin_node_id VARCHAR(255) NOT NULL, '
            . 'created_at BIGINT NOT NULL)';
    }

    private static function driver(PDO $connection, bool $allowSqliteForTesting): string
    {
        $driver = $connection->getAttribute(PDO::ATTR_DRIVER_NAME);
        $driver = is_string($driver) ? $driver : '';
        if (!in_array($driver, ['mysql', 'pgsql', 'sqlite'], true)) {
            throw new ClusterTransportException('PDO invalidation transport supports MySQL and PostgreSQL only.');
        }
        if ($driver === 'sqlite' && !$allowSqliteForTesting) {
            throw new ClusterTransportException('SQLite is not a supported shared Cluster Cache transport.');
        }

        return $driver;
    }
}
