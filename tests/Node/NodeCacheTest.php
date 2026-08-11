<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cache\Adapter\ArrayCacheAdapter;
use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\CacheLayer\Cache\Lock\LockHandle;
use Infocyph\CacheLayer\Cache\Lock\LockProviderInterface;
use Infocyph\CacheLayer\Cache\Metrics\InMemoryCacheMetricsCollector;
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

test('node cache uses the configured shared lock provider for remember operations', function () {
    $lock = new class implements LockProviderInterface {
        public int $acquired = 0;

        public int $released = 0;

        public function acquire(string $key, float $waitSeconds, float $leaseSeconds = 30.0): ?LockHandle
        {
            if ($waitSeconds < 0 || $leaseSeconds <= 0) {
                return null;
            }

            ++$this->acquired;

            return new LockHandle($key, 'test-lock', leaseSeconds: $leaseSeconds);
        }

        public function refresh(?LockHandle $handle, float $leaseSeconds): bool
        {
            return $handle instanceof LockHandle && $leaseSeconds > 0;
        }

        public function release(?LockHandle $handle): void
        {
            if (!$handle instanceof LockHandle) {
                return;
            }

            ++$this->released;
        }
    };
    $config = new NodeCacheConfig(
        sqliteFile: $this->nodeCacheFile,
        namespace: 'node.tests',
        apcuEnabled: false,
        lockProvider: $lock,
    );

    expect(NodeCache::create($config)->remember('locked', fn(): string => 'value', 300))->toBe('value')
        ->and($lock->acquired)->toBe(1)
        ->and($lock->released)->toBe(1);
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

test('node bulk reads fetch only L1 misses from SQLite and promote as one batch', function () {
    $connection = NodeSqliteConnection::create($this->nodeConfig);
    $l1 = new ArrayCacheAdapter($this->nodeConfig->namespace);
    $l2 = new NodeSqliteCacheAdapter($connection, $this->nodeConfig->namespace);
    $metrics = new InMemoryCacheMetricsCollector();
    $cache = new Cache(new NodeCacheAdapter($l1, $l2, false, $metrics), metrics: $metrics);
    $cache->setMultiple(['hot' => 1, 'cold.a' => 2, 'cold.b' => 3]);
    $l1->deleteItems(['cold.a', 'cold.b']);
    $before = $metrics->export()[NodeCacheAdapter::class] ?? [];

    expect($cache->getMultiple(['hot', 'cold.a', 'missing', 'cold.b']))
        ->toBe(['hot' => 1, 'cold.a' => 2, 'missing' => null, 'cold.b' => 3]);

    $after = $metrics->export()[NodeCacheAdapter::class] ?? [];
    expect(($after['l1_batch_hit'] ?? 0) - ($before['l1_batch_hit'] ?? 0))->toBe(1)
        ->and(($after['l1_batch_miss'] ?? 0) - ($before['l1_batch_miss'] ?? 0))->toBe(3)
        ->and(($after['l2_batch_hit'] ?? 0) - ($before['l2_batch_hit'] ?? 0))->toBe(2)
        ->and(($after['l2_batch_promote'] ?? 0) - ($before['l2_batch_promote'] ?? 0))->toBe(2);
});

test('node tagged L1 hits use cached generation metadata', function () {
    $connection = NodeSqliteConnection::create($this->nodeConfig);
    $l1 = new ArrayCacheAdapter($this->nodeConfig->namespace);
    $l2 = new NodeSqliteCacheAdapter($connection, $this->nodeConfig->namespace);
    $cache = new Cache(new NodeCacheAdapter($l1, $l2, false));
    $cache->setTagged('tagged', 'value', ['products'], 300);
    $connection->prepare(
        "DELETE FROM cachelayer_node_entries WHERE namespace = ? AND cache_key = 'm:tag:products'",
    )->execute([$this->nodeConfig->namespace]);

    expect($cache->get('tagged'))->toBe('value');
    $l1->clear();
    expect($cache->get('tagged'))->toBeNull();
});

test('node never writes L1 when its authoritative L2 write fails', function () {
    $connection = NodeSqliteConnection::create($this->nodeConfig);
    $l1 = new ArrayCacheAdapter($this->nodeConfig->namespace);
    $l2 = new NodeSqliteCacheAdapter($connection, $this->nodeConfig->namespace);
    $cache = new Cache(new NodeCacheAdapter($l1, $l2));
    $cache->set('coherent', 'old', 300);
    $l1->clear();
    $connection->exec(
        "CREATE TRIGGER reject_node_update BEFORE UPDATE ON cachelayer_node_entries "
        . "WHEN OLD.cache_key = 'd:coherent' BEGIN SELECT RAISE(ABORT, 'write rejected'); END",
    );

    expect($cache->set('coherent', 'new', 300))->toBeFalse()
        ->and($l1->getItem('coherent')->isHit())->toBeFalse()
        ->and($l2->getItem('coherent')->get())->toBe('old');
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
    expect(fn() => new NodeCacheConfig('', 'app'))->toThrow(NodeCacheConfigurationException::class)
        ->and(fn() => new NodeCacheConfig('/tmp/cache.sqlite', 'app', busyTimeoutMs: -1))
        ->toThrow(NodeCacheConfigurationException::class);
});
