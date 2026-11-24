<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Tests\Cluster;

use Infocyph\CacheLayer\Cluster\ClusterCache;
use FilesystemIterator;
use PDO;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Infocyph\CacheLayer\Cluster\ClusterCacheConfig;
use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\CacheLayer\Cluster\Consumer\InvalidationConsumer;
use Infocyph\CacheLayer\Cluster\Consumer\InvalidationHandler;
use Infocyph\CacheLayer\Cluster\Cursor\SqliteCursorStore;
use Infocyph\CacheLayer\Cluster\Event\InvalidationEvent;
use Infocyph\CacheLayer\Cluster\Event\InvalidationEventType;
use Infocyph\CacheLayer\Cluster\Exception\ClusterCacheException;
use Infocyph\CacheLayer\Cluster\Exception\ClusterTransportException;
use Infocyph\CacheLayer\Cluster\Health\ClusterStatusTracker;
use Infocyph\CacheLayer\Cluster\Recovery\ClusterRecoveryManager;
use Infocyph\CacheLayer\Cluster\Transport\InvalidationTransportData;
use Infocyph\CacheLayer\Cluster\Transport\Pdo\PdoInvalidationTransport;
use Infocyph\CacheLayer\Cluster\Transport\Pdo\PdoInvalidationSchema;
use Infocyph\CacheLayer\Node\NodeCacheConfig;
use Infocyph\CacheLayer\Tests\Cluster\Support\InMemoryInvalidationTransport;
use Infocyph\CacheLayer\Tests\Cluster\Support\RejectingClusterCacheAdapter;

$clusterFixture = null;

beforeEach(function () use (&$clusterFixture): void {
    $clusterDirectory = sys_get_temp_dir() . '/cachelayer-cluster-' . uniqid();
    $transport = new InMemoryInvalidationTransport();
    $clusterConfigA = new ClusterCacheConfig('test-cluster', 'node-a', 'memory-primary');
    $clusterConfigB = new ClusterCacheConfig('test-cluster', 'node-b', 'memory-primary');
    $nodeConfigA = new NodeCacheConfig(
        $clusterDirectory . '/node-a.sqlite',
        'application',
        apcuEnabled: false,
    );
    $nodeConfigB = new NodeCacheConfig(
        $clusterDirectory . '/node-b.sqlite',
        'application',
        apcuEnabled: false,
    );
    $nodeA = ClusterCache::create($nodeConfigA, $clusterConfigA, $transport);
    $nodeB = ClusterCache::create($nodeConfigB, $clusterConfigB, $transport);
    $clusterFixture = new class($clusterDirectory, $transport, $clusterConfigA, $clusterConfigB, $nodeConfigA, $nodeConfigB, $nodeA, $nodeB) {
        public function __construct(
            public string $clusterDirectory,
            public InMemoryInvalidationTransport $transport,
            public ClusterCacheConfig $clusterConfigA,
            public ClusterCacheConfig $clusterConfigB,
            public NodeCacheConfig $nodeConfigA,
            public NodeCacheConfig $nodeConfigB,
            public \Infocyph\CacheLayer\Cluster\ClusterRuntime $nodeA,
            public \Infocyph\CacheLayer\Cluster\ClusterRuntime $nodeB,
        ) {}
    };

});

afterEach(function () use (&$clusterFixture): void {
    if ($clusterFixture === null) {
        return;
    }

    if (!is_dir($clusterFixture->clusterDirectory)) {
        return;
    }

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($clusterFixture->clusterDirectory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($files as $file) {
        if (!$file instanceof \SplFileInfo) {
            throw new \UnexpectedValueException('Unexpected filesystem iterator entry.');
        }
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($clusterFixture->clusterDirectory);
    $clusterFixture = null;
});

test('cluster key, tag, and namespace invalidations are replayed to another node', function () use (&$clusterFixture): void {
    if ($clusterFixture === null) {
        throw new \RuntimeException('Integration fixture setup did not run.');
    }

    $clusterFixture->nodeA->cache()->set('product.42', 'A', 300);
    $clusterFixture->nodeB->cache()->set('product.42', 'B', 300);

    $clusterFixture->nodeA->invalidateKey('product.42');

    expect($clusterFixture->nodeA->cache()->get('product.42'))->toBeNull()
        ->and($clusterFixture->nodeB->cache()->get('product.42'))->toBe('B')
        ->and($clusterFixture->nodeB->consume())->toBe(1)
        ->and($clusterFixture->nodeB->cache()->get('product.42'))->toBeNull();

    $clusterFixture->nodeA->cache()->setTagged('product.43', 'A', ['products'], 300);
    $clusterFixture->nodeB->cache()->setTagged('product.43', 'B', ['products'], 300);
    $clusterFixture->nodeA->invalidateTag('products');

    expect($clusterFixture->nodeB->consume())->toBe(1)
        ->and($clusterFixture->nodeB->cache()->get('product.43'))->toBeNull();

    $clusterFixture->nodeA->cache()->set('settings', 'A', 300);
    $clusterFixture->nodeB->cache()->set('settings', 'B', 300);
    $clusterFixture->nodeA->clearNamespace();

    expect($clusterFixture->nodeB->consume())->toBe(1)
        ->and($clusterFixture->nodeB->cache()->get('settings'))->toBeNull();
});

test('recovery clears a local node when its cursor predates retained events', function () use (&$clusterFixture): void {
    if ($clusterFixture === null) {
        throw new \RuntimeException('Integration fixture setup did not run.');
    }

    $clusterFixture->transport->publish(InvalidationEvent::key('test-cluster', 'application', 'first', 'writer'));
    $clusterFixture->transport->publish(InvalidationEvent::key('test-cluster', 'application', 'second', 'writer'));
    $clusterFixture->transport->publish(InvalidationEvent::key('test-cluster', 'application', 'third', 'writer'));
    $clusterFixture->nodeB->consume(1);
    $clusterFixture->nodeB->cache()->set('stale', 'value', 300);
    $clusterFixture->transport->discardBefore('test-cluster', 3);

    expect($clusterFixture->nodeB->recoverIfRequired())->toBeTrue()
        ->and($clusterFixture->nodeB->cache()->get('stale'))->toBeNull()
        ->and($clusterFixture->nodeB->recoverIfRequired())->toBeFalse();
});

test('the origin node advances its cursor without replaying its own invalidation', function () use (&$clusterFixture): void {
    if ($clusterFixture === null) {
        throw new \RuntimeException('Integration fixture setup did not run.');
    }

    $clusterFixture->nodeA->cache()->set('origin.only', 'value', 300);
    $clusterFixture->nodeA->invalidateKey('origin.only');

    expect($clusterFixture->nodeA->consume())->toBe(1)
        ->and($clusterFixture->nodeA->cache()->get('origin.only'))->toBeNull()
        ->and($clusterFixture->nodeA->consume())->toBe(0);
});

test('cluster bulk tag invalidation publishes each unique tag once', function () use (&$clusterFixture): void {
    if ($clusterFixture === null) {
        throw new \RuntimeException('Integration fixture setup did not run.');
    }

    $clusterFixture->nodeB->cache()->setTagged('products.list', 'products', ['products'], 300);
    $clusterFixture->nodeB->cache()->setTagged('search.list', 'search', ['search'], 300);

    $clusterFixture->nodeA->invalidateTags(['products', 'search', 'products']);

    expect($clusterFixture->nodeB->consume())->toBe(2)
        ->and($clusterFixture->nodeB->cache()->get('products.list'))->toBeNull()
        ->and($clusterFixture->nodeB->cache()->get('search.list'))->toBeNull();
});

test('cursor progress is isolated by namespace on the same node and SQLite store', function () use (&$clusterFixture): void {
    if ($clusterFixture === null) {
        throw new \RuntimeException('Integration fixture setup did not run.');
    }

    $transport = new InMemoryInvalidationTransport();
    $sqliteFile = $clusterFixture->clusterDirectory . '/shared-node.sqlite';
    $cluster = new ClusterCacheConfig('scope-cluster', 'shared-node', 'memory-scope');
    $alpha = ClusterCache::create(
        new NodeCacheConfig($sqliteFile, 'alpha', apcuEnabled: false),
        $cluster,
        $transport,
    );
    $beta = ClusterCache::create(
        new NodeCacheConfig($sqliteFile, 'beta', apcuEnabled: false),
        $cluster,
        $transport,
    );

    $beta->cache()->set('shared', 'stale', 300);
    $transport->publish(InvalidationEvent::key('scope-cluster', 'beta', 'shared', 'writer'));

    expect($alpha->consume())->toBe(1)
        ->and($alpha->status()->cursor)->toBe('1')
        ->and($beta->status()->cursor)->toBeNull()
        ->and($beta->consume())->toBe(1)
        ->and($beta->cache()->get('shared'))->toBeNull()
        ->and($beta->status()->cursor)->toBe('1');
});

test('cursor progress is isolated by transport identity on the same node scope', function () use (&$clusterFixture): void {
    if ($clusterFixture === null) {
        throw new \RuntimeException('Integration fixture setup did not run.');
    }

    $transportA = new InMemoryInvalidationTransport();
    $transportB = new InMemoryInvalidationTransport();
    $sqliteFile = $clusterFixture->clusterDirectory . '/shared-transport-node.sqlite';
    $node = new NodeCacheConfig($sqliteFile, 'application', apcuEnabled: false);
    $runtimeA = ClusterCache::create(
        $node,
        new ClusterCacheConfig('transport-cluster', 'shared-node', 'transport-a'),
        $transportA,
    );
    $runtimeB = ClusterCache::create(
        $node,
        new ClusterCacheConfig('transport-cluster', 'shared-node', 'transport-b'),
        $transportB,
    );

    $runtimeB->cache()->set('beta', 'stale', 300);
    $transportA->publish(InvalidationEvent::key('transport-cluster', 'application', 'alpha', 'writer'));
    $transportB->publish(InvalidationEvent::key('transport-cluster', 'application', 'beta', 'writer'));

    expect($runtimeA->consume())->toBe(1)
        ->and($runtimeA->status()->cursor)->toBe('1')
        ->and($runtimeB->status()->cursor)->toBeNull()
        ->and($runtimeB->consume())->toBe(1)
        ->and($runtimeB->cache()->get('beta'))->toBeNull()
        ->and($runtimeB->status()->cursor)->toBe('1');
});

test('legacy cursor migration clears the affected scope before establishing new progress', function () use (&$clusterFixture): void {
    if ($clusterFixture === null) {
        throw new \RuntimeException('Integration fixture setup did not run.');
    }

    $sqliteFile = $clusterFixture->clusterDirectory . '/legacy-cursor.sqlite';
    $node = new NodeCacheConfig($sqliteFile, 'application', apcuEnabled: false);
    $cache = \Infocyph\CacheLayer\Node\NodeCache::create($node);
    $cache->set('stale', 'value', 300);

    $pdo = new PDO('sqlite:' . $sqliteFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec(
        'CREATE TABLE cachelayer_cluster_cursors ('
        . 'cluster_name TEXT NOT NULL, node_id TEXT NOT NULL, last_event_id TEXT, updated_at INTEGER NOT NULL, '
        . 'PRIMARY KEY (cluster_name, node_id)) WITHOUT ROWID',
    );
    $statement = $pdo->prepare(
        'INSERT INTO cachelayer_cluster_cursors (cluster_name, node_id, last_event_id, updated_at) VALUES (?, ?, ?, ?)',
    );
    $statement->execute(['migration-cluster', 'shared-node', '99', time()]);

    $transport = new InMemoryInvalidationTransport();
    $runtime = ClusterCache::create(
        $node,
        new ClusterCacheConfig('migration-cluster', 'shared-node', 'memory-migrated'),
        $transport,
    );

    expect($runtime->status()->cursor)->toBeNull()
        ->and($runtime->cache()->get('stale'))->toBeNull()
        ->and($runtime->status()->lastRecoveryAt)->not->toBeNull()
        ->and($runtime->cache()->get('stale'))->toBeNull()
        ->and($runtime->status()->cursor)->toBeNull()
        ->and($runtime->recoverIfRequired())->toBeFalse();
});

test('namespace-scoped v2 cursor migration is isolated per transport identity', function () use (&$clusterFixture): void {
    if ($clusterFixture === null) {
        throw new \RuntimeException('Integration fixture setup did not run.');
    }

    $sqliteFile = $clusterFixture->clusterDirectory . '/v2-cursor.sqlite';
    $node = new NodeCacheConfig($sqliteFile, 'application', apcuEnabled: false);
    $cache = \Infocyph\CacheLayer\Node\NodeCache::create($node);
    $cache->set('stale', 'value', 300);

    $pdo = new PDO('sqlite:' . $sqliteFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec(
        'CREATE TABLE cachelayer_cluster_cursors_v2 ('
        . 'cluster_name TEXT NOT NULL, node_id TEXT NOT NULL, namespace_name TEXT NOT NULL, '
        . 'last_event_id TEXT, updated_at INTEGER NOT NULL, '
        . 'PRIMARY KEY (cluster_name, node_id, namespace_name)) WITHOUT ROWID',
    );
    $statement = $pdo->prepare(
        'INSERT INTO cachelayer_cluster_cursors_v2 '
        . '(cluster_name, node_id, namespace_name, last_event_id, updated_at) VALUES (?, ?, ?, ?, ?)',
    );
    $statement->execute(['migration-cluster', 'shared-node', 'application', '42', time()]);

    $runtime = ClusterCache::create(
        $node,
        new ClusterCacheConfig('migration-cluster', 'shared-node', 'transport-v3'),
        new InMemoryInvalidationTransport(),
    );

    expect($runtime->cache()->get('stale'))->toBeNull()
        ->and($runtime->status()->lastRecoveryAt)->not->toBeNull()
        ->and($runtime->recoverIfRequired())->toBeFalse();
});


test('scoped cursor persists across runtime restart and reset', function () use (&$clusterFixture): void {
    if ($clusterFixture === null) {
        throw new \RuntimeException('Integration fixture setup did not run.');
    }

    $transport = new InMemoryInvalidationTransport();
    $sqliteFile = $clusterFixture->clusterDirectory . '/restart-node.sqlite';
    $node = new NodeCacheConfig($sqliteFile, 'application', apcuEnabled: false);
    $cluster = new ClusterCacheConfig('restart-cluster', 'restart-node', 'memory-restart');

    $transport->publish(InvalidationEvent::key('restart-cluster', 'application', 'first', 'writer'));
    $firstRuntime = ClusterCache::create($node, $cluster, $transport);
    expect($firstRuntime->consume())->toBe(1)
        ->and($firstRuntime->status()->cursor)->toBe('1');

    unset($firstRuntime);
    $secondRuntime = ClusterCache::create($node, $cluster, $transport);
    $transport->publish(InvalidationEvent::key('restart-cluster', 'application', 'second', 'writer'));

    expect($secondRuntime->status()->cursor)->toBe('1')
        ->and($secondRuntime->consume())->toBe(1)
        ->and($secondRuntime->status()->cursor)->toBe('2');

    $cursor = new SqliteCursorStore(
        $sqliteFile,
        'restart-cluster',
        'restart-node',
        'application',
        'memory-restart',
    );
    $cursor->reset('1');
    expect($cursor->current())->toBe('1');

    $cursor->reset(null);
    expect($cursor->current())->toBeNull()
        ->and($cursor->requiresRecovery())->toBeFalse();
});

test('cluster status reports cursor position, pending events, and consume results', function () use (&$clusterFixture): void {
    if ($clusterFixture === null) {
        throw new \RuntimeException('Integration fixture setup did not run.');
    }

    $clusterFixture->transport->publish(InvalidationEvent::key('test-cluster', 'application', 'first', 'writer'));
    $clusterFixture->transport->publish(InvalidationEvent::key('test-cluster', 'application', 'second', 'writer'));

    $before = $clusterFixture->nodeB->status();
    $clusterFixture->nodeB->consume(1);
    $after = $clusterFixture->nodeB->status();

    expect($before->cursor)->toBeNull()
        ->and($before->oldestAvailableEventId)->toBe('1')
        ->and($before->newestAvailableEventId)->toBe('2')
        ->and($before->pendingEventCount)->toBe(2)
        ->and($after->cursor)->toBe('1')
        ->and($after->cursorUpdatedAt)->toBeInt()
        ->and($after->pendingEventCount)->toBe(1)
        ->and($after->lastConsumeCount)->toBe(1)
        ->and($after->lastConsumeError)->toBeNull();
});

test('cluster drain consumes a bounded sequence of event batches', function () use (&$clusterFixture): void {
    if ($clusterFixture === null) {
        throw new \RuntimeException('Integration fixture setup did not run.');
    }

    $clusterFixture->transport->publish(InvalidationEvent::key('test-cluster', 'application', 'first', 'writer'));
    $clusterFixture->transport->publish(InvalidationEvent::key('test-cluster', 'application', 'second', 'writer'));
    $clusterFixture->transport->publish(InvalidationEvent::key('test-cluster', 'application', 'third', 'writer'));

    expect($clusterFixture->nodeB->drain(limit: 1, maxBatches: 2))->toBe(2)
        ->and($clusterFixture->nodeB->consume())->toBe(1);
});

test('PDO transport replays events, prunes them in batches, and participates in an outbox transaction', function () use (&$clusterFixture): void {
    if ($clusterFixture === null) {
        throw new \RuntimeException('Integration fixture setup did not run.');
    }

    $connection = new \PDO('sqlite:' . $clusterFixture->clusterDirectory . '/transport.sqlite');
    $transport = new PdoInvalidationTransport($connection, allowSqliteForTesting: true);
    $first = $transport->publish(InvalidationEvent::key('pdo-cluster', 'application', 'first', 'writer'));
    $second = $transport->publish(InvalidationEvent::tag('pdo-cluster', 'application', 'products', 'writer'));

    expect($transport->consumeAfter('pdo-cluster', $first, 10)->events)->toHaveCount(1)
        ->and($transport->oldestAvailableId('pdo-cluster'))->toBe($first)
        ->and($transport->newestAvailableId('pdo-cluster'))->toBe($second)
        ->and($transport->countAfter('pdo-cluster', $first))->toBe(1)
        ->and($transport->isCursorBefore($first, $second))->toBeTrue()
        ->and($transport->pruneBefore(time() + 1, 1))->toBe(1)
        ->and($transport->oldestAvailableId('pdo-cluster'))->toBe($second);

    $connection->beginTransaction();
    $transport->publishWithinTransaction(
        $connection,
        InvalidationEvent::namespace('pdo-cluster', 'application', 'writer'),
    );
    $connection->rollBack();

    expect($transport->consumeAfter('pdo-cluster', $second, 10)->events)->toBe([]);
});

test('PDO transport refuses SQLite unless it is explicitly test-only', function () use (&$clusterFixture): void {
    if ($clusterFixture === null) {
        throw new \RuntimeException('Integration fixture setup did not run.');
    }

    $connection = new \PDO('sqlite:' . $clusterFixture->clusterDirectory . '/unsafe-transport.sqlite');

    expect(fn () => new PdoInvalidationTransport($connection))
        ->toThrow(\Infocyph\CacheLayer\Cluster\Exception\ClusterTransportException::class);
});

test('PDO transport can use a separately bootstrapped schema without DDL on construction', function () use (&$clusterFixture): void {
    if ($clusterFixture === null) {
        throw new \RuntimeException('Integration fixture setup did not run.');
    }

    $connection = new \PDO('sqlite:' . $clusterFixture->clusterDirectory . '/preinstalled-transport.sqlite');
    PdoInvalidationSchema::install($connection, allowSqliteForTesting: true);
    $transport = new PdoInvalidationTransport(
        $connection,
        allowSqliteForTesting: true,
        initializeSchema: false,
    );

    expect($transport->publish(InvalidationEvent::key('preinstalled', 'application', 'key', 'writer')))
        ->toBe('1');
});

test('invalidation transport data rejects malformed and overflowing timestamps', function (): void {
    expect(fn () => InvalidationTransportData::unsignedInteger('-1', 'created_at', 'test transport'))
        ->toThrow(ClusterTransportException::class)
        ->and(fn () => InvalidationTransportData::unsignedInteger(
            (string) PHP_INT_MAX . '0',
            'created_at',
            'test transport',
        ))->toThrow(ClusterTransportException::class);
});

test('invalidation events enforce identifier and timestamp invariants', function (): void {
    expect(fn () => new InvalidationEvent(
        null,
        'cluster',
        'namespace',
        InvalidationEventType::Key,
        '',
        'node',
        time(),
    ))->toThrow(ClusterCacheException::class)
        ->and(fn () => new InvalidationEvent(
            null,
            'cluster',
            'namespace',
            InvalidationEventType::Namespace,
            'unexpected',
            'node',
            time(),
        ))->toThrow(ClusterCacheException::class)
        ->and(fn () => new InvalidationEvent(
            null,
            'cluster',
            'namespace',
            InvalidationEventType::Namespace,
            null,
            'node',
            -1,
        ))->toThrow(ClusterCacheException::class);
});

test('cluster configuration and runtime inputs enforce transport bounds before publication', function () use (&$clusterFixture): void {
    if ($clusterFixture === null) {
        throw new \RuntimeException('Integration fixture setup did not run.');
    }

    expect(fn() => new ClusterCacheConfig(str_repeat('c', 129), 'node', 'memory'))
        ->toThrow(\Infocyph\CacheLayer\Cluster\Exception\ClusterConfigurationException::class)
        ->and(fn() => new ClusterCacheConfig('cluster', str_repeat('n', 256), 'memory'))
        ->toThrow(\Infocyph\CacheLayer\Cluster\Exception\ClusterConfigurationException::class)
        ->and(fn() => new ClusterCacheConfig('cluster', 'node', str_repeat('t', 129)))
        ->toThrow(\Infocyph\CacheLayer\Cluster\Exception\ClusterConfigurationException::class)
        ->and(fn() => $clusterFixture->nodeA->invalidateKey(str_repeat('k', 65)))
        ->toThrow(ClusterCacheException::class)
        ->and(fn() => $clusterFixture->nodeA->invalidateTag(str_repeat('t', 65)))
        ->toThrow(ClusterCacheException::class);
});

test('poison event escape hatch clears local data before advancing the cursor', function () use (&$clusterFixture): void {
    if ($clusterFixture === null) {
        throw new \RuntimeException('Integration fixture setup did not run.');
    }

    $clusterFixture->nodeB->cache()->set('stale', 'value');
    $eventId = $clusterFixture->transport->publish(
        InvalidationEvent::key('test-cluster', 'application', 'poison', 'writer'),
    );

    $clusterFixture->nodeB->skipEventAfterClear($eventId);

    expect($clusterFixture->nodeB->cache()->get('stale'))->toBeNull()
        ->and($clusterFixture->nodeB->status()->cursor)->toBe($eventId);
});

test('transactional outbox publishes with the source transaction and applies locally after commit', function () use (&$clusterFixture): void {
    if ($clusterFixture === null) {
        throw new \RuntimeException('Integration fixture setup did not run.');
    }

    $connection = new \PDO('sqlite:' . $clusterFixture->clusterDirectory . '/outbox.sqlite');
    $transport = new PdoInvalidationTransport($connection, allowSqliteForTesting: true);
    $runtime = ClusterCache::create($clusterFixture->nodeConfigA, $clusterFixture->clusterConfigA, $transport);
    $runtime->cache()->set('product.42', 'stale', 300);

    $connection->beginTransaction();
    $outbox = $runtime->outbox($connection);
    $outbox->invalidateKey('product.42');
    $connection->commit();

    expect($runtime->cache()->get('product.42'))->toBe('stale');

    $outbox->applyLocally();

    expect($runtime->cache()->get('product.42'))->toBeNull();
});

test('transactional outbox events replay on their origin node after a post-commit crash window', function () use (&$clusterFixture): void {
    if ($clusterFixture === null) {
        throw new \RuntimeException('Integration fixture setup did not run.');
    }

    $connection = new \PDO('sqlite:' . $clusterFixture->clusterDirectory . '/outbox-replay.sqlite');
    $transport = new PdoInvalidationTransport($connection, allowSqliteForTesting: true);
    $runtime = ClusterCache::create($clusterFixture->nodeConfigA, $clusterFixture->clusterConfigA, $transport);
    $runtime->cache()->set('product.42', 'stale', 300);

    $connection->beginTransaction();
    $runtime->outbox($connection)->invalidateKey('product.42');
    $connection->commit();

    expect($runtime->consume())->toBe(1)
        ->and($runtime->cache()->get('product.42'))->toBeNull();
});

test('consumer keeps its cursor when local invalidation returns false', function () use (&$clusterFixture): void {
    if ($clusterFixture === null) {
        throw new \RuntimeException('Integration fixture setup did not run.');
    }

    $adapter = new RejectingClusterCacheAdapter();
    $cache = new Cache($adapter);
    $transport = new InMemoryInvalidationTransport();
    $transport->publish(InvalidationEvent::key('failed-cluster', 'application', 'key', 'writer'));
    $cursor = new SqliteCursorStore(
        $clusterFixture->clusterDirectory . '/failed-cursor.sqlite',
        'failed-cluster',
        'consumer',
        'application',
        'memory-primary',
    );
    $cursor->reset(null); // This test exercises replay after successful scope initialization.
    $recovery = new ClusterRecoveryManager($cache, $cursor, $transport, 'failed-cluster');
    $consumer = new InvalidationConsumer(
        $transport,
        $cursor,
        new InvalidationHandler($cache, 'application'),
        $recovery,
        'failed-cluster',
        'consumer',
        new ClusterStatusTracker(),
    );

    expect(fn() => $consumer->consume())->toThrow(ClusterCacheException::class)
        ->and($cursor->current())->toBeNull()
        ->and(array_keys($adapter->rejectedOperations))->toBe(['deleteItem']);
});

test('recovery keeps its cursor when the required clear returns false', function () use (&$clusterFixture): void {
    if ($clusterFixture === null) {
        throw new \RuntimeException('Integration fixture setup did not run.');
    }

    $adapter = new RejectingClusterCacheAdapter();
    $cache = new Cache($adapter);
    $transport = new InMemoryInvalidationTransport();
    foreach (['one', 'two', 'three'] as $key) {
        $transport->publish(InvalidationEvent::key('recovery-failure', 'application', $key, 'writer'));
    }
    $transport->discardBefore('recovery-failure', 3);
    $cursor = new SqliteCursorStore(
        $clusterFixture->clusterDirectory . '/recovery-failed-cursor.sqlite',
        'recovery-failure',
        'consumer',
        'application',
        'memory-primary',
    );
    $cursor->advance('1');
    $recovery = new ClusterRecoveryManager($cache, $cursor, $transport, 'recovery-failure');

    expect(fn() => $recovery->recoverIfRequired())->toThrow(ClusterCacheException::class)
        ->and($cursor->current())->toBe('1')
        ->and(array_keys($adapter->rejectedOperations))->toBe(['clear']);
});


test('recovery clears stale local state when retained invalidation history disappears completely', function () use (&$clusterFixture): void {
    if ($clusterFixture === null) {
        throw new \RuntimeException('Integration fixture setup did not run.');
    }

    $clusterFixture->transport->publish(
        InvalidationEvent::key('test-cluster', 'application', 'first', 'writer'),
    );
    expect($clusterFixture->nodeB->consume())->toBe(1);

    $clusterFixture->nodeB->cache()->set('stale-after-loss', 'value', 300);
    $clusterFixture->transport->publish(
        InvalidationEvent::key('test-cluster', 'application', 'stale-after-loss', 'writer'),
    );
    $clusterFixture->transport->discardBefore('test-cluster', PHP_INT_MAX);

    expect($clusterFixture->nodeB->recoverIfRequired())->toBeTrue()
        ->and($clusterFixture->nodeB->cache()->get('stale-after-loss'))->toBeNull()
        ->and($clusterFixture->nodeB->status()->cursor)->toBeNull()
        ->and($clusterFixture->nodeB->recoverIfRequired())->toBeFalse();
});

test('recovery clears and replays when a recreated transport restarts behind the stored cursor', function () use (&$clusterFixture): void {
    if ($clusterFixture === null) {
        throw new \RuntimeException('Integration fixture setup did not run.');
    }

    $clusterFixture->transport->publish(
        InvalidationEvent::key('test-cluster', 'application', 'one', 'writer'),
    );
    $clusterFixture->transport->publish(
        InvalidationEvent::key('test-cluster', 'application', 'two', 'writer'),
    );
    expect($clusterFixture->nodeB->consume(2))->toBe(2)
        ->and($clusterFixture->nodeB->status()->cursor)->toBe('2');

    $clusterFixture->nodeB->cache()->set('reset-key', 'stale', 300);

    $replacementTransport = new InMemoryInvalidationTransport();
    $replacementTransport->publish(
        InvalidationEvent::key('test-cluster', 'application', 'reset-key', 'writer'),
    );
    $replacementRuntime = ClusterCache::create(
        $clusterFixture->nodeConfigB,
        $clusterFixture->clusterConfigB,
        $replacementTransport,
    );

    expect($replacementRuntime->recoverIfRequired())->toBeTrue()
        ->and($replacementRuntime->cache()->get('reset-key'))->toBeNull()
        ->and($replacementRuntime->status()->cursor)->toBeNull()
        ->and($replacementRuntime->consume())->toBe(1)
        ->and($replacementRuntime->status()->cursor)->toBe('1');
});


test('PDO recovery clears stale local state after complete retained-history loss', function () use (&$clusterFixture): void {
    if ($clusterFixture === null) {
        throw new \RuntimeException('Integration fixture setup did not run.');
    }

    $connection = new PDO('sqlite:' . $clusterFixture->clusterDirectory . '/history-loss.sqlite');
    $transport = new PdoInvalidationTransport($connection, allowSqliteForTesting: true);
    $node = new NodeCacheConfig(
        $clusterFixture->clusterDirectory . '/history-loss-node.sqlite',
        'application',
        apcuEnabled: false,
    );
    $cluster = new ClusterCacheConfig('pdo-history-loss', 'consumer', 'pdo-history-loss');
    $runtime = ClusterCache::create($node, $cluster, $transport);

    $transport->publish(
        InvalidationEvent::key('pdo-history-loss', 'application', 'first', 'writer'),
    );
    expect($runtime->consume())->toBe(1);

    $runtime->cache()->set('stale', 'value', 300);
    $transport->publish(
        InvalidationEvent::key('pdo-history-loss', 'application', 'stale', 'writer'),
    );
    $connection->exec(
        "DELETE FROM " . PdoInvalidationSchema::EVENT_TABLE . " WHERE cluster_name = 'pdo-history-loss'",
    );

    expect($runtime->recoverIfRequired())->toBeTrue()
        ->and($runtime->cache()->get('stale'))->toBeNull()
        ->and($runtime->status()->cursor)->toBeNull()
        ->and($runtime->recoverIfRequired())->toBeFalse();
});

test('a new history identity clears local state before exposing overlapping recreated events', function () use (&$clusterFixture): void {
    if ($clusterFixture === null) {
        throw new \RuntimeException('Integration fixture setup did not run.');
    }

    $connection = new PDO('sqlite:' . $clusterFixture->clusterDirectory . '/epoch-events.sqlite');
    $transport = new PdoInvalidationTransport($connection, allowSqliteForTesting: true);
    $node = new NodeCacheConfig($clusterFixture->clusterDirectory . '/epoch-node.sqlite', 'application', apcuEnabled: false);
    $old = ClusterCache::create($node, new ClusterCacheConfig('epoch', 'consumer', 'history-1'), $transport);
    foreach (['one', 'two'] as $key) {
        $transport->publish(InvalidationEvent::key('epoch', 'application', $key, 'writer'));
    }
    expect($old->consume())->toBe(2);
    $old->cache()->set('stale', 'old value');
    unset($old);
    $connection->exec('DELETE FROM ' . PdoInvalidationSchema::EVENT_TABLE);
    $connection->exec("DELETE FROM sqlite_sequence WHERE name = 'cachelayer_invalidation_events'");
    foreach (['stale', 'one', 'two'] as $key) {
        $transport->publish(InvalidationEvent::key('epoch', 'application', $key, 'writer'));
    }
    $config = new ClusterCacheConfig('epoch', 'consumer', 'history-2');
    $replacement = ClusterCache::create($node, $config, $transport);
    expect($replacement->cache()->get('stale'))->toBeNull()
        ->and($replacement->status()->cursor)->toBeNull()
        ->and($replacement->consume())->toBe(3);
    $replacement->cache()->set('warm', 'kept');
    unset($replacement);
    $restarted = ClusterCache::create($node, $config, $transport);
    expect($restarted->cache()->get('warm'))->toBe('kept')
        ->and($restarted->status()->cursor)->toBe('3')
        ->and($restarted->consume())->toBe(0);
});


test('a failed initial clear leaves a new history scope uninitialized for retry', function () use (&$clusterFixture): void {
    if ($clusterFixture === null) {
        throw new \RuntimeException('Integration fixture setup did not run.');
    }

    $cursor = new SqliteCursorStore(
        $clusterFixture->clusterDirectory . '/uninitialized.sqlite',
        'new-history',
        'consumer',
        'application',
        'generation-2',
    );
    $transport = new InMemoryInvalidationTransport();
    $failed = new ClusterRecoveryManager(new Cache(new RejectingClusterCacheAdapter()), $cursor, $transport, 'new-history');
    expect($cursor->requiresRecovery())->toBeTrue()
        ->and(fn() => $failed->recoverIfRequired())->toThrow(ClusterCacheException::class)
        ->and($cursor->requiresRecovery())->toBeTrue()
        ->and($cursor->updatedAt())->toBeNull();

    $cache = Cache::memory('application');
    $cache->set('stale', 'value');
    $retry = new ClusterRecoveryManager($cache, $cursor, $transport, 'new-history');
    expect($retry->recoverIfRequired())->toBeTrue()
        ->and($cache->get('stale'))->toBeNull()
        ->and($cursor->requiresRecovery())->toBeFalse()
        ->and($cursor->current())->toBeNull()
        ->and($retry->recoverIfRequired())->toBeFalse();
});

test('direct invalidation event construction enforces transport ID validation', function (): void {
    foreach (['', 'invalid-id', str_repeat('1', 129)] as $id) {
        expect(fn() => new InvalidationEvent(
            $id,
            'cluster',
            'namespace',
            InvalidationEventType::Key,
            'key',
            'node',
            time(),
        ))->toThrow(ClusterCacheException::class);
    }

    $event = new InvalidationEvent('123-0', 'cluster', 'namespace', InvalidationEventType::Key, 'key', 'node', time());
    expect($event->id)->toBe('123-0');
});
