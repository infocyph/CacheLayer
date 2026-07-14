<?php

use Infocyph\CacheLayer\Cluster\ClusterCache;
use Infocyph\CacheLayer\Cluster\ClusterCacheConfig;
use Infocyph\CacheLayer\Cluster\Event\InvalidationEvent;
use Infocyph\CacheLayer\Cluster\Transport\Pdo\PdoInvalidationTransport;
use Infocyph\CacheLayer\Node\NodeCacheConfig;
use Infocyph\CacheLayer\Tests\Cluster\Support\InMemoryInvalidationTransport;

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

test('PDO transport replays events, prunes them in batches, and participates in an outbox transaction', function () {
    $connection = new \PDO('sqlite:' . $this->clusterDirectory . '/transport.sqlite');
    $transport = new PdoInvalidationTransport($connection);
    $first = $transport->publish(InvalidationEvent::key('pdo-cluster', 'application', 'first', 'writer'));
    $second = $transport->publish(InvalidationEvent::tag('pdo-cluster', 'application', 'products', 'writer'));

    expect($transport->consumeAfter('pdo-cluster', $first, 10)->events)->toHaveCount(1)
        ->and($transport->oldestAvailableId('pdo-cluster'))->toBe($first)
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
