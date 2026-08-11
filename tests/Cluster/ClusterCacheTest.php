<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cluster\ClusterCache;
use Infocyph\CacheLayer\Cluster\ClusterCacheConfig;
use Infocyph\CacheLayer\Cache\Adapter\AbstractCacheAdapter;
use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\CacheLayer\Cache\Item\CacheItem;
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
use Psr\Cache\CacheItemInterface;

final class RejectingClusterCacheAdapter extends AbstractCacheAdapter
{
    /** @var array<string, mixed> */
    public array $rejectedOperations = [];

    public function clear(): bool
    {
        return $this->reject('clear');
    }

    public function deleteItem(string $key): bool
    {
        return $this->reject('deleteItem', $key);
    }

    public function deleteItems(array $keys): bool
    {
        return $this->reject('deleteItems', $keys);
    }

    public function getItem(string $key): CacheItem
    {
        return $this->genericMiss($key);
    }

    public function hasItem(string $key): bool
    {
        return $this->reject('hasItem', $key);
    }

    public function multiFetch(array $keys): array
    {
        $items = [];
        foreach ($keys as $key) {
            $items[$key] = $this->genericMiss($key);
        }

        return $items;
    }

    public function save(CacheItemInterface $item): bool
    {
        return $this->reject('save', $item);
    }

    public function saveItems(array $items): bool
    {
        return $this->reject('saveItems', $items);
    }

    private function reject(string $operation, mixed $argument = null): bool
    {
        $this->rejectedOperations[$operation] = $argument;

        return false;
    }
}

beforeEach(function () {
    $this->clusterDirectory = sys_get_temp_dir() . '/cachelayer-cluster-' . uniqid();
    $this->transport = new InMemoryInvalidationTransport();
    $this->clusterConfigA = new ClusterCacheConfig('test-cluster', 'node-a');
    $this->clusterConfigB = new ClusterCacheConfig('test-cluster', 'node-b');
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
    expect(fn() => new ClusterCacheConfig(str_repeat('c', 129), 'node'))
        ->toThrow(\Infocyph\CacheLayer\Cluster\Exception\ClusterConfigurationException::class)
        ->and(fn() => new ClusterCacheConfig('cluster', str_repeat('n', 256)))
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
    );
    $cursor->advance('1');
    $recovery = new ClusterRecoveryManager($cache, $cursor, $transport, 'recovery-failure');

    expect(fn() => $recovery->recoverIfRequired())->toThrow(ClusterCacheException::class)
        ->and($cursor->current())->toBe('1')
        ->and(array_keys($adapter->rejectedOperations))->toBe(['clear']);
});
