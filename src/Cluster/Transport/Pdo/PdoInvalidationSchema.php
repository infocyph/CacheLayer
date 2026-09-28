<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cluster\Transport\Pdo;

use Infocyph\CacheLayer\Cluster\Exception\ClusterTransportException;
use PDO;
use PDOException;

final class PdoInvalidationSchema
{
    public const string EVENT_TABLE = 'cachelayer_invalidation_events';

    public const string LOCK_TABLE = 'cachelayer_invalidation_clusters';

    public static function install(PDO $connection, bool $allowSqliteForTesting = false): void
    {
        $driver = self::driver($connection, $allowSqliteForTesting);

        try {
            $connection->exec(self::createEventTableSql($driver));
            if ($driver === 'mysql' && !self::mysqlEventIdentityColumnsAreBinary($connection)) {
                self::hardenMysqlEventIdentityColumns($connection);
            }
            self::createIndex($connection, $driver);
            $connection->exec(self::createLockTableSql($driver));
            if ($driver === 'mysql' && !self::mysqlLockIdentityIsBinary($connection)) {
                self::hardenMysqlLockIdentity($connection);
            }
        } catch (PDOException $exception) {
            throw new ClusterTransportException('Unable to initialize the PDO invalidation transport schema.', 0, $exception);
        }
    }

    public static function validateConnection(PDO $connection, bool $allowSqliteForTesting = false): string
    {
        return self::driver($connection, $allowSqliteForTesting);
    }

    private static function createEventTableSql(string $driver): string
    {
        $id = match ($driver) {
            'mysql' => 'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY',
            'pgsql' => 'BIGSERIAL PRIMARY KEY',
            default => 'INTEGER PRIMARY KEY AUTOINCREMENT',
        };
        $identity = $driver === 'mysql'
            ? ' CHARACTER SET ascii COLLATE ascii_bin'
            : '';

        return 'CREATE TABLE IF NOT EXISTS ' . self::EVENT_TABLE . ' ('
            . 'event_id ' . $id
            . ', cluster_name VARCHAR(128)' . $identity . ' NOT NULL'
            . ', namespace_name VARCHAR(64)' . $identity . ' NOT NULL'
            . ', event_type VARCHAR(32)' . $identity . ' NOT NULL'
            . ', identifier VARCHAR(64)' . $identity . ' NULL'
            . ', origin_node_id VARCHAR(255)' . $identity . ' NOT NULL'
            . ', created_at BIGINT NOT NULL)';
    }

    private static function createIndex(PDO $connection, string $driver): void
    {
        $ifNotExists = $driver === 'mysql' ? '' : ' IF NOT EXISTS';

        try {
            $connection->exec(
                'CREATE INDEX' . $ifNotExists . ' cachelayer_invalidation_events_cluster_idx '
                . 'ON ' . self::EVENT_TABLE . ' (cluster_name, event_id)',
            );
        } catch (PDOException $exception) {
            $duplicate = is_array($exception->errorInfo) && ($exception->errorInfo[1] ?? null) === 1061;
            if ($driver !== 'mysql' || !$duplicate) {
                throw $exception;
            }
        }
    }

    private static function createLockTableSql(string $driver): string
    {
        $identity = $driver === 'mysql'
            ? 'VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin'
            : 'VARCHAR(128)';

        return 'CREATE TABLE IF NOT EXISTS ' . self::LOCK_TABLE . ' ('
            . 'cluster_name ' . $identity . ' NOT NULL PRIMARY KEY)';
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

    private static function hardenMysqlEventIdentityColumns(PDO $connection): void
    {
        $connection->exec(
            'ALTER TABLE ' . self::EVENT_TABLE . ' '
            . 'MODIFY cluster_name VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . 'MODIFY namespace_name VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . 'MODIFY event_type VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . 'MODIFY identifier VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL, '
            . 'MODIFY origin_node_id VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL',
        );
    }

    private static function hardenMysqlLockIdentity(PDO $connection): void
    {
        $connection->exec(
            'ALTER TABLE ' . self::LOCK_TABLE . ' '
            . 'MODIFY cluster_name VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL',
        );
    }

    private static function mysqlBinaryIdentityCount(PDO $connection, string $table): int
    {
        $columns = $table === self::EVENT_TABLE
            ? ['cluster_name', 'namespace_name', 'event_type', 'identifier', 'origin_node_id']
            : ['cluster_name'];
        $marks = implode(', ', array_fill(0, count($columns), '?'));
        $statement = $connection->prepare(
            'SELECT COUNT(*) FROM information_schema.columns '
            . 'WHERE table_schema = DATABASE() AND table_name = ? '
            . 'AND column_name IN (' . $marks . ") AND collation_name = 'ascii_bin'",
        );
        $statement->execute([$table, ...$columns]);

        return (int) $statement->fetchColumn();
    }

    private static function mysqlEventIdentityColumnsAreBinary(PDO $connection): bool
    {
        return self::mysqlBinaryIdentityCount($connection, self::EVENT_TABLE) === 5;
    }

    private static function mysqlLockIdentityIsBinary(PDO $connection): bool
    {
        return self::mysqlBinaryIdentityCount($connection, self::LOCK_TABLE) === 1;
    }
}
