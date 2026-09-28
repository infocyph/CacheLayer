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
            $pdo->exec('DROP TABLE IF EXISTS cachelayer_invalidation_clusters');
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
            $pdo->exec('DROP TABLE IF EXISTS cachelayer_invalidation_clusters');
        }
    }
});



$orderingBackends = static function (): array {
    $servicePassword = getenv('IC_SERVICE_PASSWORD');
    $servicePassword = $servicePassword === false ? '' : $servicePassword;
    $serviceUser = getenv('IC_SERVICE_USERNAME') ?: 'phpforge';

    return [
        'mysql' => [
            getenv('IC_MYSQL_DSN') ?: 'mysql:host=127.0.0.1;port=3306;dbname=phpforge;charset=utf8mb4',
            getenv('IC_MYSQL_USER') ?: $serviceUser,
            getenv('IC_MYSQL_PASSWORD') ?: $servicePassword,
        ],
        'pgsql' => [
            getenv('IC_POSTGRES_DSN') ?: 'pgsql:host=127.0.0.1;port=5432;dbname=cachelayer',
            getenv('IC_POSTGRES_USER') ?: $serviceUser,
            getenv('IC_POSTGRES_PASSWORD') ?: $servicePassword,
        ],
    ];
};

$connectOrderingBackend = static function (array $backend): PDO {
    [$dsn, $user, $password] = $backend;

    return new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
};

$resetInvalidationSchema = static function (PDO $pdo): void {
    $pdo->exec('DROP TABLE IF EXISTS cachelayer_invalidation_events');
    $pdo->exec('DROP TABLE IF EXISTS cachelayer_invalidation_clusters');
    PdoInvalidationSchema::install($pdo);
};

$waitForState = static function (string $path, string $expected): void {
    for ($attempt = 0; $attempt < 200; ++$attempt) {
        clearstatcache(true, $path);
        if (is_file($path) && trim((string) file_get_contents($path)) === $expected) {
            return;
        }
        usleep(10_000);
    }

    throw new RuntimeException('Timed out waiting for the concurrent invalidation publisher.');
};

$removeTestFile = static function (string $path): void {
    if (is_file($path) && !unlink($path)) {
        throw new RuntimeException('Unable to remove an invalidation concurrency test file.');
    }
};

$startPublisher = static function (
    array $backend,
    string $cluster,
    string $identifier,
    string $stateFile,
    string $resultFile,
    bool $commit = true,
    int $holdMicros = 0,
): mixed {
    [$dsn, $user, $password] = $backend;
    $command = [
        PHP_BINARY,
        __DIR__ . '/Support/PdoInvalidationPublisherProcess.php',
        $dsn,
        $user,
        $password,
        $cluster,
        $identifier,
        $stateFile,
        $resultFile,
        $commit ? '1' : '0',
        (string) $holdMicros,
        'worker-child',
    ];
    $process = proc_open(
        $command,
        [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', '/dev/null', 'a'],
            2 => ['file', $resultFile . '.stderr', 'a'],
        ],
        $pipes,
        dirname(__DIR__, 2),
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start the invalidation publisher process.');
    }

    return $process;
};

$finishPublisher = static function (mixed $process, string $stderrFile): int {
    if (!is_resource($process)) {
        throw new RuntimeException('Invalid concurrent invalidation publisher process.');
    }

    $exitCode = proc_close($process);
    if ($exitCode !== 0) {
        $stderr = is_file($stderrFile) ? trim((string) file_get_contents($stderrFile)) : '';
        throw new RuntimeException('Concurrent invalidation publisher failed: ' . $stderr);
    }

    return $exitCode;
};

test('PDO invalidation publication serializes ID allocation through transaction completion', function () use (
    $orderingBackends,
    $connectOrderingBackend,
    $resetInvalidationSchema,
    $waitForState,
    $removeTestFile,
    $startPublisher,
    $finishPublisher,
) {
    foreach ($orderingBackends() as $backend) {
        $admin = $connectOrderingBackend($backend);
        $resetInvalidationSchema($admin);
        $firstConnection = $connectOrderingBackend($backend);
        $firstTransport = new PdoInvalidationTransport($firstConnection, initializeSchema: false);
        $firstConnection->beginTransaction();
        $firstId = $firstTransport->publishWithinTransaction(
            $firstConnection,
            InvalidationEvent::key('ordered-cluster', 'application', 'first', 'worker-a'),
        );

        $stateFile = tempnam(sys_get_temp_dir(), 'cachelayer-order-state-');
        $resultFile = tempnam(sys_get_temp_dir(), 'cachelayer-order-result-');
        if ($stateFile === false || $resultFile === false) {
            throw new RuntimeException('Unable to allocate invalidation concurrency test files.');
        }
        file_put_contents($stateFile, '');
        file_put_contents($resultFile, '');
        $stderrFile = $resultFile . '.stderr';

        $process = $startPublisher(
            $backend,
            'ordered-cluster',
            'second',
            $stateFile,
            $resultFile,
        );

        try {
            $waitForState($stateFile, 'started');
            usleep(150_000);
            expect(file_get_contents($resultFile))->toBe('')
                ->and(trim((string) file_get_contents($stateFile)))->toBe('started');

            $firstConnection->commit();
            expect($finishPublisher($process, $stderrFile))->toBe(0);
            $secondId = trim((string) file_get_contents($resultFile));
            $events = (new PdoInvalidationTransport($admin, initializeSchema: false))
                ->consumeAfter('ordered-cluster', null, 10)
                ->events;

            expect($secondId)->not->toStartWith('error:')
                ->and(array_map(static fn($event): string => (string) $event->id, $events))
                ->toBe([$firstId, $secondId])
                ->and(array_map(static fn($event): ?string => $event->identifier, $events))
                ->toBe(['first', 'second']);
        } finally {
            if ($firstConnection->inTransaction()) {
                $firstConnection->rollBack();
            }
            $removeTestFile($stateFile);
            $removeTestFile($resultFile);
            $removeTestFile($stderrFile);
            $admin->exec('DROP TABLE IF EXISTS cachelayer_invalidation_events');
            $admin->exec('DROP TABLE IF EXISTS cachelayer_invalidation_clusters');
        }
    }
});

test('PDO invalidation publication survives rollback and publisher process death', function () use (
    $orderingBackends,
    $connectOrderingBackend,
    $resetInvalidationSchema,
    $waitForState,
    $removeTestFile,
    $startPublisher,
    $finishPublisher,
) {
    foreach ($orderingBackends() as $backend) {
        $admin = $connectOrderingBackend($backend);
        $resetInvalidationSchema($admin);

        $abortedConnection = $connectOrderingBackend($backend);
        $abortedTransport = new PdoInvalidationTransport($abortedConnection, initializeSchema: false);
        $abortedConnection->beginTransaction();
        $abortedTransport->publishWithinTransaction(
            $abortedConnection,
            InvalidationEvent::key('rollback-cluster', 'application', 'aborted', 'worker-a'),
        );

        $stateFile = tempnam(sys_get_temp_dir(), 'cachelayer-rollback-state-');
        $resultFile = tempnam(sys_get_temp_dir(), 'cachelayer-rollback-result-');
        if ($stateFile === false || $resultFile === false) {
            throw new RuntimeException('Unable to allocate invalidation rollback test files.');
        }
        file_put_contents($stateFile, '');
        file_put_contents($resultFile, '');
        $stderrFile = $resultFile . '.stderr';

        $process = $startPublisher(
            $backend,
            'rollback-cluster',
            'committed',
            $stateFile,
            $resultFile,
        );

        try {
            $waitForState($stateFile, 'started');
            usleep(150_000);
            expect(file_get_contents($resultFile))->toBe('')
                ->and(trim((string) file_get_contents($stateFile)))->toBe('started');

            $abortedConnection->rollBack();
            expect($finishPublisher($process, $stderrFile))->toBe(0);
            $events = (new PdoInvalidationTransport($admin, initializeSchema: false))
                ->consumeAfter('rollback-cluster', null, 10)
                ->events;

            expect(array_map(static fn($event): ?string => $event->identifier, $events))
                ->toBe(['committed']);
        } finally {
            if ($abortedConnection->inTransaction()) {
                $abortedConnection->rollBack();
            }
            $removeTestFile($stateFile);
            $removeTestFile($resultFile);
            $removeTestFile($stderrFile);
        }

        $resetInvalidationSchema($admin);
        $stateFile = tempnam(sys_get_temp_dir(), 'cachelayer-death-state-');
        $resultFile = tempnam(sys_get_temp_dir(), 'cachelayer-death-result-');
        if ($stateFile === false || $resultFile === false) {
            throw new RuntimeException('Unable to allocate invalidation process-death test files.');
        }
        file_put_contents($stateFile, '');
        file_put_contents($resultFile, '');
        $stderrFile = $resultFile . '.stderr';

        $process = $startPublisher(
            $backend,
            'death-cluster',
            'aborted',
            $stateFile,
            $resultFile,
            commit: false,
            holdMicros: 150_000,
        );

        try {
            $waitForState($stateFile, 'acquired');
            $survivor = new PdoInvalidationTransport($connectOrderingBackend($backend), initializeSchema: false);
            $survivorId = $survivor->publish(
                InvalidationEvent::key('death-cluster', 'application', 'survivor', 'worker-b'),
            );
            expect($finishPublisher($process, $stderrFile))->toBe(0);
            $events = (new PdoInvalidationTransport($admin, initializeSchema: false))
                ->consumeAfter('death-cluster', null, 10)
                ->events;

            expect($survivorId)->not->toBe('')
                ->and(array_map(static fn($event): ?string => $event->identifier, $events))
                ->toBe(['survivor']);
        } finally {
            $removeTestFile($stateFile);
            $removeTestFile($resultFile);
            $removeTestFile($stderrFile);
            $admin->exec('DROP TABLE IF EXISTS cachelayer_invalidation_events');
            $admin->exec('DROP TABLE IF EXISTS cachelayer_invalidation_clusters');
        }
    }
});
