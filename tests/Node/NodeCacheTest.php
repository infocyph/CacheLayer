<?php

use Infocyph\CacheLayer\Cache\Adapter\ArrayCacheAdapter;
use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\CacheLayer\Node\Adapter\NodeCacheAdapter;
use Infocyph\CacheLayer\Node\Adapter\NodeSqliteCacheAdapter;
use Infocyph\CacheLayer\Node\Connection\NodeSqliteConnection;
use Infocyph\CacheLayer\Node\Exception\NodeCacheConfigurationException;
use Infocyph\CacheLayer\Node\Maintenance\NodeCachePruner;
use Infocyph\CacheLayer\Node\NodeCache;
use Infocyph\CacheLayer\Node\NodeCacheConfig;

beforeEach(function () {
    $this->nodeCacheDirectory = sys_get_temp_dir() . '/cachelayer-node-' . uniqid();
    $this->nodeCacheFile = $this->nodeCacheDirectory . '/cache.sqlite';
    $this->nodeConfig = new NodeCacheConfig(
        sqliteFile: $this->nodeCacheFile,
        namespace: 'node.tests',
        apcuEnabled: false,
        lockDirectory: $this->nodeCacheDirectory . '/locks',
    );
});

afterEach(function () {
    if (!is_dir($this->nodeCacheDirectory)) {
        return;
    }

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($this->nodeCacheDirectory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($files as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($this->nodeCacheDirectory);
});

test('node cache factory provides SQLite-backed facade behavior and local tags', function () {
    $cache = NodeCache::create($this->nodeConfig);

    expect($cache->setTagged('user.42', ['name' => 'Ada'], ['users'], 300))->toBeTrue()
        ->and($cache->get('user.42'))->toBe(['name' => 'Ada'])
        ->and($cache->invalidateTag('users'))->toBeTrue()
        ->and($cache->get('user.42'))->toBeNull();
});

test('node cache supports deferred writes through its composed PSR-6 item', function () {
    $cache = NodeCache::create($this->nodeConfig);
    $item = $cache->getItem('deferred')->set('value')->expiresAfter(300);

    expect($cache->saveDeferred($item))->toBeTrue()
        ->and($cache->commit())->toBeTrue()
        ->and($cache->get('deferred'))->toBe('value');
});

test('SQLite hits promote into an L1 cache without returning the child item', function () {
    $connection = NodeSqliteConnection::create($this->nodeConfig);
    $l1 = new ArrayCacheAdapter($this->nodeConfig->namespace);
    $l2 = new NodeSqliteCacheAdapter($connection, $this->nodeConfig->namespace);
    $cache = new Cache(new NodeCacheAdapter($l1, $l2, false));

    expect($cache->set('promoted', 'value', 300))->toBeTrue();
    $l1->clear();

    $item = $cache->getItem('promoted');

    expect($item->isHit())->toBeTrue()
        ->and($item->get())->toBe('value')
        ->and($l1->getItem('promoted')->isHit())->toBeTrue();

    $item->set('changed');
    expect($cache->save($item))->toBeTrue()
        ->and($l2->getItem('promoted')->get())->toBe('changed');
});

test('expired rows remain outside the read path until bounded pruning', function () {
    $connection = NodeSqliteConnection::create($this->nodeConfig);
    $adapter = new NodeSqliteCacheAdapter($connection, $this->nodeConfig->namespace);
    $statement = $connection->prepare(
        'INSERT INTO cachelayer_node_entries (namespace, cache_key, payload, expires_at) VALUES (?, ?, ?, ?)',
    );
    $statement->execute([$this->nodeConfig->namespace, 'stale', 'invalid-payload', time() - 1]);

    expect($adapter->getItem('stale')->isHit())->toBeFalse()
        ->and((int) $connection->query("SELECT COUNT(*) FROM cachelayer_node_entries WHERE cache_key = 'stale'")->fetchColumn())
        ->toBe(1)
        ->and((new NodeCachePruner($connection, $this->nodeConfig->namespace))->pruneExpired(1))
        ->toBe(1)
        ->and((int) $connection->query("SELECT COUNT(*) FROM cachelayer_node_entries WHERE cache_key = 'stale'")->fetchColumn())
        ->toBe(0);
});

test('node cache configuration rejects invalid paths and timeouts', function () {
    expect(fn () => new NodeCacheConfig('', 'app'))->toThrow(NodeCacheConfigurationException::class)
        ->and(fn () => new NodeCacheConfig('/tmp/cache.sqlite', 'app', busyTimeoutMs: -1))
        ->toThrow(NodeCacheConfigurationException::class);
});
