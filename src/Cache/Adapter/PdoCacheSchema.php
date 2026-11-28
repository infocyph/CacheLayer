<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cache\Adapter;

use PDO;
use PDOException;
use RuntimeException;
use Throwable;

final class PdoCacheSchema
{
    /**
     * @throws RuntimeException
     */
    public static function install(PDO $pdo, string $table = 'cachelayer_entries'): void
    {
        if (preg_match('/^[A-Za-z0-9_]+$/D', $table) !== 1) {
            throw new RuntimeException('Invalid PDO cache table name.');
        }

        $driverValue = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $driver = is_string($driverValue) ? $driverValue : '';
        $identifier = in_array($driver, ['mysql', 'mariadb'], true)
            ? 'VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin'
            : 'TEXT';
        $payload = match ($driver) {
            'mysql', 'mariadb' => 'MEDIUMBLOB',
            'pgsql' => 'BYTEA',
            default => 'BLOB',
        };
        if ($driver === 'pgsql') {
            self::installPostgres($pdo, $table, $identifier, $payload);

            return;
        }

        $mysql = in_array($driver, ['mysql', 'mariadb'], true);
        self::installTable($pdo, $table, $identifier, $payload, $mysql);
        if ($mysql) {
            $engine = $pdo->prepare('SELECT ENGINE FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
            $engine->execute([$table]);
            if (strtolower((string) $engine->fetchColumn()) !== 'innodb') {
                throw new RuntimeException('PDO MySQL cache tables must use InnoDB.');
            }
            if (!self::mysqlIdentityColumnsAreBinary($pdo, $table)) {
                self::hardenMysqlIdentityColumns($pdo, $table);
            }
        }

        self::installExpiryIndex($pdo, $table, $driver);
    }

    private static function hardenMysqlIdentityColumns(PDO $pdo, string $table): void
    {
        $pdo->exec(
            "ALTER TABLE {$table} "
            . 'MODIFY namespace VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . 'MODIFY kind VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . 'MODIFY cache_key VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL',
        );
    }

    /**
     * @throws PDOException
     */
    private static function installExpiryIndex(PDO $pdo, string $table, string $driver): void
    {
        $index = $table . '_expires_idx';

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            try {
                $pdo->exec("CREATE INDEX {$index} ON {$table}(namespace, kind, expires)");
            } catch (PDOException $exception) {
                $duplicate = is_array($exception->errorInfo) && ($exception->errorInfo[1] ?? null) === 1061;
                if (!$duplicate) {
                    throw $exception;
                }
            }

            return;
        }

        $pdo->exec("CREATE INDEX IF NOT EXISTS {$index} ON {$table}(namespace, kind, expires)");
    }

    /**
     * @throws PDOException
     */
    private static function installPostgres(PDO $pdo, string $table, string $identifier, string $payload): void
    {
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

        try {
            // Match PostgreSQL's case folding and length limit for unquoted names.
            $lock = $pdo->prepare(
                "SELECT pg_advisory_xact_lock(hashtext('cachelayer:schema'), hashtext(CAST(CAST(? AS name) AS text)))",
            );
            $lock->execute([strtolower($table)]);
            $lock->closeCursor();
            self::installTable($pdo, $table, $identifier, $payload);
            self::installExpiryIndex($pdo, $table, 'pgsql');
            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (PDOException $failure) {
            try {
                if ($ownsTransaction && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            } catch (Throwable) {
                // Preserve the original schema installation failure.
            }

            throw $failure;
        }
    }

    private static function installTable(PDO $pdo, string $table, string $identifier, string $payload, bool $mysql = false): void
    {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS {$table} (
                namespace {$identifier} NOT NULL,
                kind {$identifier} NOT NULL,
                cache_key {$identifier} NOT NULL,
                payload {$payload} NOT NULL,
                expires BIGINT NULL,
                PRIMARY KEY (namespace, kind, cache_key)
            )" . ($mysql ? ' ENGINE=InnoDB' : ''),
        );
    }

    private static function mysqlIdentityColumnsAreBinary(PDO $pdo, string $table): bool
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.columns '
            . 'WHERE table_schema = DATABASE() AND table_name = ? '
            . "AND column_name IN ('namespace', 'kind', 'cache_key') "
            . "AND collation_name = 'ascii_bin'",
        );
        $statement->execute([$table]);

        return (int) $statement->fetchColumn() === 3;
    }
}
