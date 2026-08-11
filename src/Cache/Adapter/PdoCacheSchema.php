<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cache\Adapter;

use PDO;
use PDOException;
use RuntimeException;

final class PdoCacheSchema
{
    public static function install(PDO $pdo, string $table = 'cachelayer_entries'): void
    {
        if (preg_match('/^[A-Za-z0-9_]+$/D', $table) !== 1) {
            throw new RuntimeException('Invalid PDO cache table name.');
        }

        $driverValue = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $driver = is_string($driverValue) ? $driverValue : '';
        $identifier = in_array($driver, ['mysql', 'mariadb'], true) ? 'VARCHAR(191)' : 'TEXT';
        $payload = match ($driver) {
            'mysql', 'mariadb' => 'MEDIUMBLOB',
            'pgsql' => 'BYTEA',
            default => 'BLOB',
        };
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS {$table} (
                namespace {$identifier} NOT NULL,
                kind {$identifier} NOT NULL,
                cache_key {$identifier} NOT NULL,
                payload {$payload} NOT NULL,
                expires BIGINT NULL,
                PRIMARY KEY (namespace, kind, cache_key)
            )",
        );

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
}
