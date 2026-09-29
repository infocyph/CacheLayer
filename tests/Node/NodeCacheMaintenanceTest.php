<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Node\Adapter\NodeSqliteCacheAdapter;
use Infocyph\CacheLayer\Node\Connection\NodeSqliteConnection;
use Infocyph\CacheLayer\Node\Maintenance\NodeCacheMaintenance;
use Infocyph\CacheLayer\Node\Maintenance\NodeCachePruner;
use Infocyph\CacheLayer\Node\NodeCacheConfig;

beforeEach(function (): void {
    $this->maintenanceDirectory = sys_get_temp_dir() . '/cachelayer-maintenance-' . uniqid();
    $this->maintenanceConfig = new NodeCacheConfig(
        $this->maintenanceDirectory . '/cache.sqlite',
        'maintenance',
        apcuEnabled: false,
    );
});

afterEach(function (): void {
    if (!is_dir($this->maintenanceDirectory)) {
        return;
    }

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($this->maintenanceDirectory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($files as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($this->maintenanceDirectory);
});

it('runs one bounded prune checkpoint and optimize maintenance unit', function (): void {
    $connection = NodeSqliteConnection::create($this->maintenanceConfig);
    new NodeSqliteCacheAdapter($connection, $this->maintenanceConfig->namespace);
    $statement = $connection->prepare(
        'INSERT INTO cachelayer_node_entries (namespace, cache_key, payload, expires_at) VALUES (?, ?, ?, ?)',
    );
    $statement->execute([
        $this->maintenanceConfig->namespace,
        'expired',
        'unused',
        time() - 1,
    ]);
    $maintenance = new NodeCacheMaintenance(
        $connection,
        new NodeCachePruner($connection, $this->maintenanceConfig->namespace),
    );

    expect($maintenance->cycle(pruneLimit: 1, checkpoint: true, optimize: true))->toBe(1)
        ->and((int) $connection->query(
            "SELECT COUNT(*) FROM cachelayer_node_entries WHERE cache_key = 'expired'",
        )->fetchColumn())->toBe(0);
});
