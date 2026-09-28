<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cache\Adapter\PdoCacheSchema;
use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\CacheLayer\Cluster\Event\InvalidationEvent;
use Infocyph\CacheLayer\Cluster\Transport\Pdo\PdoInvalidationSchema;
use Infocyph\CacheLayer\Cluster\Transport\Pdo\PdoInvalidationTransport;

$backends = static function (): array {
    $servicePassword = getenv('IC_SERVICE_PASSWORD');
    $servicePassword = $servicePassword === false ? '' : $servicePassword;

    return [
        'mysql' => [
            getenv('IC_MYSQL_DSN') ?: 'mysql:host=127.0.0.1;port=3306;dbname=phpforge;charset=utf8mb4',
            getenv('IC_MYSQL_USER') ?: getenv('IC_SERVICE_USERNAME') ?: 'phpforge',
            getenv('IC_MYSQL_PASSWORD') ?: $servicePassword,
        ],
        'mariadb' => [
            getenv('IC_MARIADB_DSN') ?: 'mysql:host=127.0.0.1;port=3308;dbname=phpforge;charset=utf8mb4',
            getenv('IC_MARIADB_USER') ?: getenv('IC_SERVICE_USERNAME') ?: 'phpforge',
            getenv('IC_MARIADB_PASSWORD') ?: $servicePassword,
        ],
    ];
};

test('MySQL-family cache and invalidation identities use byte-sensitive collations', function () use ($backends) {
    expect(in_array('mysql', PDO::getAvailableDrivers(), true))->toBeTrue();

    foreach ($backends() as $name => [$dsn, $user, $password]) {
        $pdo = new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $cacheTable = 'cachelayer_identity_' . $name;

        try {
            $pdo->exec("DROP TABLE IF EXISTS {$cacheTable}");
            $pdo->exec(
                "CREATE TABLE {$cacheTable} ("
                . 'namespace VARCHAR(191) NOT NULL, kind VARCHAR(191) NOT NULL, '
                . 'cache_key VARCHAR(191) NOT NULL, payload MEDIUMBLOB NOT NULL, expires BIGINT NULL, '
                . 'PRIMARY KEY (namespace, kind, cache_key)) '
                . 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci',
            );

            PdoCacheSchema::install($pdo, $cacheTable);

            $statement = $pdo->prepare(
                'SELECT column_name, collation_name FROM information_schema.columns '
                . 'WHERE table_schema = DATABASE() AND table_name = ? '
                . "AND column_name IN ('namespace', 'kind', 'cache_key')",
            );
            $statement->execute([$cacheTable]);
            $collations = $statement->fetchAll(PDO::FETCH_COLUMN, 1);
            expect(array_values(array_unique($collations)))->toBe(['ascii_bin']);

            $upper = Cache::pdo('Tenant', pdo: $pdo, table: $cacheTable);
            $lower = Cache::pdo('tenant', pdo: $pdo, table: $cacheTable);
            expect($upper->set('Key', 'upper'))->toBeTrue()
                ->and($lower->set('key', 'lower'))->toBeTrue()
                ->and($upper->get('Key'))->toBe('upper')
                ->and($lower->get('key'))->toBe('lower');

            $pdo->exec('DROP TABLE IF EXISTS cachelayer_invalidation_events');
            $pdo->exec(
                'CREATE TABLE cachelayer_invalidation_events ('
                . 'event_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, '
                . 'cluster_name VARCHAR(128) NOT NULL, namespace_name VARCHAR(64) NOT NULL, '
                . 'event_type VARCHAR(32) NOT NULL, identifier VARCHAR(64) NULL, '
                . 'origin_node_id VARCHAR(255) NOT NULL, created_at BIGINT NOT NULL) '
                . 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci',
            );

            PdoInvalidationSchema::install($pdo);
            $statement = $pdo->prepare(
                'SELECT column_name, collation_name FROM information_schema.columns '
                . "WHERE table_schema = DATABASE() AND table_name = 'cachelayer_invalidation_events' "
                . "AND column_name IN ('cluster_name', 'namespace_name', 'event_type', 'identifier', 'origin_node_id')",
            );
            $statement->execute();
            $collations = $statement->fetchAll(PDO::FETCH_COLUMN, 1);
            expect(array_values(array_unique($collations)))->toBe(['ascii_bin']);

            $transport = new PdoInvalidationTransport($pdo, initializeSchema: false);
            $transport->publish(InvalidationEvent::key('Tenant', 'App', 'Key', 'Node'));
            $transport->publish(InvalidationEvent::key('tenant', 'app', 'key', 'node'));

            expect($transport->countAfter('Tenant', null))->toBe(1)
                ->and($transport->countAfter('tenant', null))->toBe(1)
                ->and($transport->consumeAfter('Tenant', null, 10)->events[0]->cluster)->toBe('Tenant')
                ->and($transport->consumeAfter('tenant', null, 10)->events[0]->cluster)->toBe('tenant');
        } finally {
            $pdo->exec("DROP TABLE IF EXISTS {$cacheTable}");
            $pdo->exec('DROP TABLE IF EXISTS cachelayer_invalidation_events');
        }
    }
});
