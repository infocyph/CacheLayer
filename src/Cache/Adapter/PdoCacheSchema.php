<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cache\Adapter;

use RuntimeException;

final class PdoCacheSchema
{
    public static function install(\PDO $pdo, string $table = 'cachelayer_entries'): void
    {
        if (preg_match('/^[A-Za-z0-9_]+$/D', $table) !== 1) {
            throw new RuntimeException('Invalid PDO cache table name.');
        }

        $driverValue = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $driver = is_string($driverValue) ? $driverValue : '';
        $keyType = in_array($driver, ['mysql', 'mariadb'], true) ? 'VARCHAR(191)' : 'TEXT';
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS {$table} (
                ckey {$keyType} PRIMARY KEY,
                payload TEXT NOT NULL,
                expires BIGINT NULL
            )",
        );

        $index = $table . '_expires_idx';

        try {
            $pdo->exec("CREATE INDEX IF NOT EXISTS {$index} ON {$table}(expires)");
        } catch (\PDOException) {
            try {
                $pdo->exec("CREATE INDEX {$index} ON {$table}(expires)");
            } catch (\PDOException) {
                // The index already exists or the driver does not support this syntax.
            }
        }
    }
}
