<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Tests\Node;

use Infocyph\CacheLayer\Node\Adapter\NodeSqliteCacheAdapter;
use Infocyph\CacheLayer\Node\Connection\NodeSqliteConnection;
use Infocyph\CacheLayer\Node\Maintenance\NodeCacheMaintenance;
use Infocyph\CacheLayer\Node\Maintenance\NodeCachePruner;
use Infocyph\CacheLayer\Node\NodeCacheConfig;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

$maintenanceFixtureDirectory = '';
$maintenanceFixtureConfig = new NodeCacheConfig(sys_get_temp_dir() . '/cachelayer-maintenance-fixture.sqlite', 'maintenance', apcuEnabled: false);

beforeEach(function () use (&$maintenanceFixtureDirectory, &$maintenanceFixtureConfig): void {
    $maintenanceFixtureDirectory = sys_get_temp_dir() . '/cachelayer-maintenance-' . uniqid();
    $maintenanceFixtureConfig = new NodeCacheConfig(
        $maintenanceFixtureDirectory . '/cache.sqlite',
        'maintenance',
        apcuEnabled: false,
    );
});

afterEach(function () use (&$maintenanceFixtureDirectory): void {
    if (!is_dir($maintenanceFixtureDirectory)) {
        return;
    }

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($maintenanceFixtureDirectory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($files as $file) {
        if (!$file instanceof \SplFileInfo) {
            throw new \UnexpectedValueException('Unexpected filesystem iterator entry.');
        }
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($maintenanceFixtureDirectory);
});

it('runs one bounded prune checkpoint and optimize maintenance unit', function () use (&$maintenanceFixtureConfig): void {
    $connection = NodeSqliteConnection::create($maintenanceFixtureConfig);
    new NodeSqliteCacheAdapter($connection, $maintenanceFixtureConfig->namespace);
    $statement = $connection->prepare(
        'INSERT INTO cachelayer_node_entries (namespace, cache_key, payload, expires_at) VALUES (?, ?, ?, ?)',
    );
    $statement->execute([
        $maintenanceFixtureConfig->namespace,
        'expired',
        'unused',
        time() - 1,
    ]);
    $maintenance = new NodeCacheMaintenance(
        $connection,
        new NodeCachePruner($connection, $maintenanceFixtureConfig->namespace),
    );

    expect($maintenance->cycle(pruneLimit: 1, checkpoint: true, optimize: true))->toBe(1)
        ->and(\Infocyph\CacheLayer\Tests\Support\CacheFixture::countRows(
            $connection,
            "SELECT COUNT(*) FROM cachelayer_node_entries WHERE cache_key = 'expired'",
        ))->toBe(0);
});
