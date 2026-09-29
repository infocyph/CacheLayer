<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cluster\ClusterCache;
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

beforeEach(function () {
    $this->clusterDirectory = sys_get_temp_dir() . '/cachelayer-cluster-' . uniqid();
    $this->transport = new InMemoryInvalidationTransport();
    $this->clusterConfigA = new ClusterCacheConfig('test-cluster', 'node-a', 'memory-primary');
    $this->clusterConfigB = new ClusterCacheConfig('test-cluster', 'node-b', 'memory-primary');
    $this->nodeConfigA = new NodeCacheConfig(
        $this->clusterDirectory . '/node-a.sqlite',
        'application',
        apcuEnabled: false,
    );
    $this->nodeConfigB = new NodeCacheConfig(
        $this->clusterDirectory . '/node-b.sqlite',
        'application',
        apcuEnabled: false,
    );
    $this->nodeA = ClusterCache::create($this->nodeConfigA, $this->clusterConfigA, $this->transport);
    $this->nodeB = ClusterCache::create($this->nodeConfigB, $this->clusterConfigB, $this->transport);
});

afterEach(function () {
    if (!is_dir($this->clusterDirectory)) {
        return;
    }

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($this->clusterDirectory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($files as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($this->clusterDirectory);
});

test('cluster key, tag, and namespace invalidations are replayed to another node', function () {
    $this->nodeA->cache()->set('product.42', 'A', 300);
    $this->nodeB->cache()->set('product.42', 'B', 300);

    $this->nodeA->invalidateKey('product.42');

    expect($this->nodeA->cache()->get('product.42'))->toBeNull()
        ->and($this->nodeB->cache()->get('product.42'))->toBe('B')
        ->and($this->nodeB->consume())->toBe(1)
        ->and($this->nodeB->cache()->get('product.42'))->toBeNull();

    $this->nodeA->cache()->setTagged('product.43', 'A', ['products'], 300);
    $this->nodeB->cache()->setTagged('product.43', 'B', ['products'], 300);
    $this->nodeA->invalidateTag('products');

    expect($this->nodeB->consume())->toBe(1)
        ->and($this->nodeB->cache()->get('product.43'))->toBeNull();

    $this->nodeA->cache()->set('settings', 'A', 300);
    $this->nodeB->cache()->set('settings', 'B', 300);
    $this->nodeA->clearNamespace();

    expect($this->nodeB->consume())->toBe(1)
        ->and($this->nodeB->cache()->get('settings'))->toBeNull();
});

test('recovery clears a local node when its cursor predates retained events', function () {
    $this->transport->publish(InvalidationEvent::key('test-cluster', 'application', 'first', 'writer'));
    $this->transport->publish(InvalidationEvent::key('test-cluster', 'application', 'second', 'writer'));
    $this->transport->publish(InvalidationEvent::key('test-cluster', 'application', 'third', 'writer'));
    $this->nodeB->consume(1);
    $this->nodeB->cache()->set('stale', 'value', 300);
    $this->transport->discardBefore('test-cluster', 3);

    expect($this->nodeB->recoverIfRequired())->toBeTrue()
        ->and($this->nodeB->cache()->get('stale'))->toBeNull()
        ->and($this->nodeB->recoverIfRequired())->toBeFalse();
});

test('the origin node advances its cursor without replaying its own invalidation', function () {
    $this->nodeA->cache()->set('origin.only', 'value', 300);
    $this->nodeA->invalidateKey('origin.only');

    expect($this->nodeA->consume())->toBe(1)
        ->and($this->nodeA->cache()->get('origin.only'))->toBeNull()
        ->and($this->nodeA->consume())->toBe(0);
});

test('cluster bulk tag invalidation publishes each unique tag once', function () {
    $this->nodeB->cache()->setTagged('products.list', 'products', ['products'], 300);
    $this->nodeB->cache()->setTagged('search.list', 'search', ['search'], 300);

    $this->nodeA->invalidateTags(['products', 'search', 'products']);

    expect($this->nodeB->consume())->toBe(2)
        ->and($this->nodeB->cache()->get('products.list'))->toBeNull()
        ->and($this->nodeB->cache()->get('search.list'))->toBeNull();
});

test('cursor progress is isolated by namespace on the same node and SQLite store', function () {
    $transport = new InMemoryInvalidationTransport();
    $sqliteFile = $this->clusterDirectory . '/shared-node.sqlite';
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

test('cursor progress is isolated by transport identity on the same node scope', function () {
    $transportA = new InMemoryInvalidationTransport();
    $transportB = new InMemoryInvalidationTransport();
    $sqliteFile = $this->clusterDirectory . '/shared-transport-node.sqlite';
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

test('legacy cursor migration clears the affected scope before establishing new progress', function () {
    $sqliteFile = $this->clusterDirectory . '/legacy-cursor.sqlite';
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
        ->and($runtime->cache()->get('stale'))->toBe('value')
        ->and($runtime->recoverIfRequired())->toBeTrue()
        ->and($runtime->cache()->get('stale'))->toBeNull()
        ->and($runtime->status()->cursor)->toBeNull()
        ->and($runtime->recoverIfRequired())->toBeFalse();
});

test('namespace-scoped v2 cursor migration is isolated per transport identity', function () {
    $sqliteFile = $this->clusterDirectory . '/v2-cursor.sqlite';
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

    expect($runtime->recoverIfRequired())->toBeTrue()
        ->and($runtime->cache()->get('stale'))->toBeNull()
        ->and($runtime->recoverIfRequired())->toBeFalse();
});


test('scoped cursor persists across runtime restart and reset', function () {
    $transport = new InMemoryInvalidationTransport();
    $sqliteFile = $this->clusterDirectory . '/restart-node.sqlite';
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

test('cluster status reports cursor position, pending events, and consume results', function () {
    $this->transport->publish(InvalidationEvent::key('test-cluster', 'application', 'first', 'writer'));
    $this->transport->publish(InvalidationEvent::key('test-cluster', 'application', 'second', 'writer'));

    $before = $this->nodeB->status();
    $this->nodeB->consume(1);
    $after = $this->nodeB->status();

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

test('cluster drain consumes a bounded sequence of event batches', function () {
    $this->transport->publish(InvalidationEvent::key('test-cluster', 'application', 'first', 'writer'));
    $this->transport->publish(InvalidationEvent::key('test-cluster', 'application', 'second', 'writer'));
    $this->transport->publish(InvalidationEvent::key('test-cluster', 'application', 'third', 'writer'));

    expect($this->nodeB->drain(limit: 1, maxBatches: 2))->toBe(2)
        ->and($this->nodeB->consume())->toBe(1);
});

test('PDO transport replays events, prunes them in batches, and participates in an outbox transaction', function () {
    $connection = new \PDO('sqlite:' . $this->clusterDirectory . '/transport.sqlite');
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

test('PDO transport refuses SQLite unless it is explicitly test-only', function () {
    $connection = new \PDO('sqlite:' . $this->clusterDirectory . '/unsafe-transport.sqlite');

    expect(fn () => new PdoInvalidationTransport($connection))
        ->toThrow(\Infocyph\CacheLayer\Cluster\Exception\ClusterTransportException::class);
});

test('PDO transport can use a separately bootstrapped schema without DDL on construction', function () {
    $connection = new \PDO('sqlite:' . $this->clusterDirectory . '/preinstalled-transport.sqlite');
    PdoInvalidationSchema::install($connection, allowSqliteForTesting: true);
    $transport = new PdoInvalidationTransport(
        $connection,
        allowSqliteForTesting: true,
        initializeSchema: false,
    );

    expect($transport->publish(InvalidationEvent::key('preinstalled', 'application', 'key', 'writer')))
        ->toBe('1');
});

test('invalidation transport data rejects malformed and overflowing timestamps', function () {
    expect(fn () => InvalidationTransportData::unsignedInteger('-1', 'created_at', 'test transport'))
        ->toThrow(ClusterTransportException::class)
        ->and(fn () => InvalidationTransportData::unsignedInteger(
            (string) PHP_INT_MAX . '0',
            'created_at',
            'test transport',
        ))->toThrow(ClusterTransportException::class);
});

test('invalidation events enforce identifier and timestamp invariants', function () {
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

test('cluster configuration and runtime inputs enforce transport bounds before publication', function () {
    expect(fn() => new ClusterCacheConfig(str_repeat('c', 129), 'node', 'memory'))
        ->toThrow(\Infocyph\CacheLayer\Cluster\Exception\ClusterConfigurationException::class)
        ->and(fn() => new ClusterCacheConfig('cluster', str_repeat('n', 256), 'memory'))
        ->toThrow(\Infocyph\CacheLayer\Cluster\Exception\ClusterConfigurationException::class)
        ->and(fn() => new ClusterCacheConfig('cluster', 'node', str_repeat('t', 129)))
        ->toThrow(\Infocyph\CacheLayer\Cluster\Exception\ClusterConfigurationException::class)
        ->and(fn() => $this->nodeA->invalidateKey(str_repeat('k', 65)))
        ->toThrow(ClusterCacheException::class)
        ->and(fn() => $this->nodeA->invalidateTag(str_repeat('t', 65)))
        ->toThrow(ClusterCacheException::class);
});

test('poison event escape hatch clears local data before advancing the cursor', function () {
    $this->nodeB->cache()->set('stale', 'value');
    $eventId = $this->transport->publish(
        InvalidationEvent::key('test-cluster', 'application', 'poison', 'writer'),
    );

    $this->nodeB->skipEventAfterClear($eventId);

    expect($this->nodeB->cache()->get('stale'))->toBeNull()
        ->and($this->nodeB->status()->cursor)->toBe($eventId);
});

test('transactional outbox publishes with the source transaction and applies locally after commit', function () {
    $connection = new \PDO('sqlite:' . $this->clusterDirectory . '/outbox.sqlite');
    $transport = new PdoInvalidationTransport($connection, allowSqliteForTesting: true);
    $runtime = ClusterCache::create($this->nodeConfigA, $this->clusterConfigA, $transport);
    $runtime->cache()->set('product.42', 'stale', 300);

    $connection->beginTransaction();
    $outbox = $runtime->outbox($connection);
    $outbox->invalidateKey('product.42');
    $connection->commit();

    expect($runtime->cache()->get('product.42'))->toBe('stale');

    $outbox->applyLocally();

    expect($runtime->cache()->get('product.42'))->toBeNull();
});

test('transactional outbox events replay on their origin node after a post-commit crash window', function () {
    $connection = new \PDO('sqlite:' . $this->clusterDirectory . '/outbox-replay.sqlite');
    $transport = new PdoInvalidationTransport($connection, allowSqliteForTesting: true);
    $runtime = ClusterCache::create($this->nodeConfigA, $this->clusterConfigA, $transport);
    $runtime->cache()->set('product.42', 'stale', 300);

    $connection->beginTransaction();
    $runtime->outbox($connection)->invalidateKey('product.42');
    $connection->commit();

    expect($runtime->consume())->toBe(1)
        ->and($runtime->cache()->get('product.42'))->toBeNull();
});

test('consumer keeps its cursor when local invalidation returns false', function () {
    $adapter = new RejectingClusterCacheAdapter();
    $cache = new Cache($adapter);
    $transport = new InMemoryInvalidationTransport();
    $transport->publish(InvalidationEvent::key('failed-cluster', 'application', 'key', 'writer'));
    $cursor = new SqliteCursorStore(
        $this->clusterDirectory . '/failed-cursor.sqlite',
        'failed-cluster',
        'consumer',
        'application',
        'memory-primary',
    );
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

test('recovery keeps its cursor when the required clear returns false', function () {
    $adapter = new RejectingClusterCacheAdapter();
    $cache = new Cache($adapter);
    $transport = new InMemoryInvalidationTransport();
    foreach (['one', 'two', 'three'] as $key) {
        $transport->publish(InvalidationEvent::key('recovery-failure', 'application', $key, 'writer'));
    }
    $transport->discardBefore('recovery-failure', 3);
    $cursor = new SqliteCursorStore(
        $this->clusterDirectory . '/recovery-failed-cursor.sqlite',
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


test('recovery clears stale local state when retained invalidation history disappears completely', function () {
    $this->transport->publish(
        InvalidationEvent::key('test-cluster', 'application', 'first', 'writer'),
    );
    expect($this->nodeB->consume())->toBe(1);

    $this->nodeB->cache()->set('stale-after-loss', 'value', 300);
    $this->transport->publish(
        InvalidationEvent::key('test-cluster', 'application', 'stale-after-loss', 'writer'),
    );
    $this->transport->discardBefore('test-cluster', PHP_INT_MAX);

    expect($this->nodeB->recoverIfRequired())->toBeTrue()
        ->and($this->nodeB->cache()->get('stale-after-loss'))->toBeNull()
        ->and($this->nodeB->status()->cursor)->toBeNull()
        ->and($this->nodeB->recoverIfRequired())->toBeFalse();
});

test('recovery clears and replays when a recreated transport restarts behind the stored cursor', function () {
    $this->transport->publish(
        InvalidationEvent::key('test-cluster', 'application', 'one', 'writer'),
    );
    $this->transport->publish(
        InvalidationEvent::key('test-cluster', 'application', 'two', 'writer'),
    );
    expect($this->nodeB->consume(2))->toBe(2)
        ->and($this->nodeB->status()->cursor)->toBe('2');

    $this->nodeB->cache()->set('reset-key', 'stale', 300);

    $replacementTransport = new InMemoryInvalidationTransport();
    $replacementTransport->publish(
        InvalidationEvent::key('test-cluster', 'application', 'reset-key', 'writer'),
    );
    $replacementRuntime = ClusterCache::create(
        $this->nodeConfigB,
        $this->clusterConfigB,
        $replacementTransport,
    );

    expect($replacementRuntime->recoverIfRequired())->toBeTrue()
        ->and($replacementRuntime->cache()->get('reset-key'))->toBeNull()
        ->and($replacementRuntime->status()->cursor)->toBeNull()
        ->and($replacementRuntime->consume())->toBe(1)
        ->and($replacementRuntime->status()->cursor)->toBe('1');
});
