<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Tests\Node;

use Infocyph\CacheLayer\Cache\Adapter\AbstractCacheAdapter;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Infocyph\CacheLayer\Cache\Adapter\ArrayCacheAdapter;
use Infocyph\CacheLayer\Cache\Adapter\ApcuCacheAdapter;
use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\CacheLayer\Cache\CacheOptions;
use Infocyph\CacheLayer\Cache\Item\CacheItem;
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
use Infocyph\CacheLayer\Tests\Cache\Support\FaultingFileCacheAdapter;
use Psr\Cache\CacheItemInterface;

$nodeFixtureDirectory = '';
$nodeFixtureFile = '';
$nodeFixtureConfig = new NodeCacheConfig(sys_get_temp_dir() . '/cachelayer-node-fixture.sqlite', 'node.tests', apcuEnabled: false);

beforeEach(function () use (&$nodeFixtureDirectory, &$nodeFixtureFile, &$nodeFixtureConfig): void {
    $nodeFixtureDirectory = sys_get_temp_dir() . '/cachelayer-node-' . uniqid();
    $nodeFixtureFile = $nodeFixtureDirectory . '/cache.sqlite';
    $nodeFixtureConfig = new NodeCacheConfig(
        sqliteFile: $nodeFixtureFile,
        namespace: 'node.tests',
        apcuEnabled: false,
        lockDirectory: $nodeFixtureDirectory . '/locks',
    );
});

test('node fences L1 after a mutation fails under either failure policy', function (string $operation, bool $failOpen, bool $throws) use (&$nodeFixtureDirectory, &$nodeFixtureConfig): void {
    $l1 = new FaultingFileCacheAdapter('node.failure', $nodeFixtureDirectory . '/l1');
    $l2 = new NodeSqliteCacheAdapter(NodeSqliteConnection::create($nodeFixtureConfig), 'node.failure');
    $adapter = new NodeCacheAdapter($l1, $l2, $failOpen);
    $cache = new Cache($adapter, options: new CacheOptions(failOpen: $failOpen));
    $cache->set('key', 'old', 300);
    $l1->failure = $operation;
    $l1->throws = $throws;

    $mutate = match ($operation) {
        'save' => fn(): bool => $cache->set('key', 'new', 300),
        'saveItems' => fn(): bool => $cache->setMultiple(['key' => 'new'], 300),
        'deleteItem' => fn(): bool => $cache->delete('key'),
        'deleteItems' => fn(): bool => $cache->deleteMultiple(['key']),
        'clear' => fn(): bool => $cache->clear(),
        default => throw new \InvalidArgumentException('Unknown test mutation operation.'),
    };

    if ($throws && !$failOpen) {
        expect($mutate)->toThrow(RuntimeException::class);
    } else {
        expect($mutate())->toBeFalse();
    }

    expect($adapter->isAuthoritative())->toBeTrue()
        ->and($cache->get('key'))->toBe($l2->getItem('key')->get());
})->with(['save', 'saveItems', 'deleteItem', 'deleteItems', 'clear'])
    ->with([false, true])->with([false, true]);

test('node preserves the mutation failure when L1 cleanup also throws', function () use (&$nodeFixtureDirectory, &$nodeFixtureConfig): void {
    $l1 = new class('node.cleanup', $nodeFixtureDirectory . '/l1') extends \Infocyph\CacheLayer\Cache\Adapter\FileCacheAdapter {
        public bool $fault = false;

        #[\Override]
        public function save(CacheItemInterface $item): bool
        {
            if ($this->fault) {
                throw new RuntimeException('original write failure');
            }

            return parent::save($item);
        }

        #[\Override]
        public function clear(): bool
        {
            if ($this->fault) {
                throw new RuntimeException('cleanup failure');
            }

            return parent::clear();
        }
    };
    $l2 = new NodeSqliteCacheAdapter(NodeSqliteConnection::create($nodeFixtureConfig), 'node.cleanup');
    $adapter = new NodeCacheAdapter($l1, $l2, false);
    $item = $adapter->createItem('key')->set('old');
    $adapter->save($item);
    $l1->fault = true;

    expect(fn() => $adapter->save($item->set('new')))->toThrow(RuntimeException::class, 'original write failure')
        ->and($adapter->getItem('key')->get())->toBe('new');
});

test('node fences failed single and bulk promotions under either failure policy', function (bool $bulk, bool $failOpen, bool $throws) use (&$nodeFixtureDirectory, &$nodeFixtureConfig): void {
    $l1 = new FaultingFileCacheAdapter('node.promotion', $nodeFixtureDirectory . '/l1');
    $l2 = new NodeSqliteCacheAdapter(NodeSqliteConnection::create($nodeFixtureConfig), 'node.promotion');
    $adapter = new NodeCacheAdapter($l1, $l2, $failOpen);
    $cache = new Cache($adapter, options: new CacheOptions(failOpen: $failOpen));
    $cache->set('key', 'value');
    $l1->clear();
    $l1->failure = $bulk ? 'saveItems' : 'save';
    $l1->throws = $throws;
    $read = $bulk ? fn(): iterable => $cache->getMultiple(['key']) : fn(): mixed => $cache->get('key');

    if ($throws && !$failOpen) {
        expect($read)->toThrow(RuntimeException::class);
    } else {
        expect($read())->toBe($bulk ? ['key' => 'value'] : 'value');
    }

    expect($adapter->isAuthoritative())->toBeTrue()
        ->and($cache->get('key'))->toBe('value');
})->with([false, true])->with([false, true])->with([false, true]);

test('node fences L1 when copying a committed value into L1 throws', function (bool $bulk) use (&$nodeFixtureDirectory, &$nodeFixtureConfig): void {
    $l1 = new class('node.copy', $nodeFixtureDirectory . '/l1') extends \Infocyph\CacheLayer\Cache\Adapter\FileCacheAdapter {
        public bool $fault = false;

        #[\Override]
        public function createItem(string $key): CacheItemInterface
        {
            if ($this->fault) {
                throw new RuntimeException('copy failure');
            }

            return parent::createItem($key);
        }
    };
    $l2 = new NodeSqliteCacheAdapter(NodeSqliteConnection::create($nodeFixtureConfig), 'node.copy');
    $adapter = new NodeCacheAdapter($l1, $l2, false);
    $cache = new Cache($adapter, options: new CacheOptions(failOpen: false));
    $cache->set('key', 'old');
    $l1->fault = true;

    expect(fn() => $bulk ? $cache->setMultiple(['key' => 'new']) : $cache->set('key', 'new'))
        ->toThrow(RuntimeException::class)
        ->and($cache->get('key'))->toBe('new');
})->with([false, true]);

test('node fences L1 when a tag metadata mutation throws', function (string $operation) use (&$nodeFixtureConfig): void {
    $l1 = new class('node.tags.' . uniqid()) extends ApcuCacheAdapter {
        public ?string $failure = null;

        #[\Override]
        public function rotateTagGenerations(array $tags): bool
        {
            if ($this->failure === 'rotate') {
                throw new RuntimeException('tag rotation failure');
            }

            return parent::rotateTagGenerations($tags);
        }

        #[\Override]
        public function storeTagGenerations(array $generations): bool
        {
            if ($this->failure === 'store') {
                throw new RuntimeException('tag storage failure');
            }

            return parent::storeTagGenerations($generations);
        }
    };
    $l2 = new NodeSqliteCacheAdapter(NodeSqliteConnection::create($nodeFixtureConfig), 'node.tags');
    $adapter = new NodeCacheAdapter($l1, $l2, false);
    $cache = new Cache($adapter, options: new CacheOptions(failOpen: false));
    $cache->setTagged('key', 'old', ['group']);
    $l1->failure = $operation;

    expect(fn() => $operation === 'rotate'
        ? $cache->invalidateTag('group')
        : $adapter->storeTagGenerations(['group' => bin2hex(random_bytes(16))]))
        ->toThrow(RuntimeException::class)
        ->and($adapter->isAuthoritative())->toBeTrue()
        ->and($cache->get('key'))->toBeNull();
})->with(['rotate', 'store']);

afterEach(function () use (&$nodeFixtureDirectory): void {
    if (!is_dir($nodeFixtureDirectory)) {
        return;
    }

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($nodeFixtureDirectory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($files as $file) {
        if (!$file instanceof \SplFileInfo) {
            throw new \UnexpectedValueException('Unexpected filesystem iterator entry.');
        }
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($nodeFixtureDirectory);
});

test('node cache factory provides SQLite-backed facade behavior and local tags', function () use (&$nodeFixtureConfig): void {
    $cache = NodeCache::create($nodeFixtureConfig);

    expect($cache->setTagged('user.42', ['name' => 'Ada'], ['users'], 300))->toBeTrue()
        ->and($cache->get('user.42'))->toBe(['name' => 'Ada'])
        ->and($cache->invalidateTag('users'))->toBeTrue()
        ->and($cache->get('user.42'))->toBeNull();
});

test('node cache supports deferred writes through its composed PSR-6 item', function () use (&$nodeFixtureConfig): void {
    $cache = NodeCache::create($nodeFixtureConfig);
    $item = $cache->getItem('deferred')->set('value')->expiresAfter(300);

    expect($cache->saveDeferred($item))->toBeTrue()
        ->and($cache->commit())->toBeTrue()
        ->and($cache->get('deferred'))->toBe('value');
});

test('node cache uses the configured shared lock provider for remember operations', function () use (&$nodeFixtureFile): void {
    $lock = new class implements LockProviderInterface {
        public int $acquired = 0;

        public int $released = 0;

        #[\Override]
        public function acquire(string $key, float $waitSeconds, float $leaseSeconds = 30.0): ?LockHandle
        {
            if ($waitSeconds < 0 || $leaseSeconds <= 0) {
                return null;
            }

            ++$this->acquired;

            return new LockHandle($key, 'test-lock', leaseSeconds: $leaseSeconds);
        }

        #[\Override]
        public function refresh(?LockHandle $handle, float $leaseSeconds): bool
        {
            return $handle instanceof LockHandle && $leaseSeconds > 0;
        }

        #[\Override]
        public function release(?LockHandle $handle): void
        {
            if (!$handle instanceof LockHandle) {
                return;
            }

            ++$this->released;
        }
    };
    $config = new NodeCacheConfig(
        sqliteFile: $nodeFixtureFile,
        namespace: 'node.tests',
        apcuEnabled: false,
        lockProvider: $lock,
    );

    expect(NodeCache::create($config)->remember('locked', fn(): string => 'value', 300))->toBe('value')
        ->and($lock->acquired)->toBe(1)
        ->and($lock->released)->toBe(1);
});

test('SQLite hits promote into an L1 cache without returning the child item', function () use (&$nodeFixtureConfig): void {
    $connection = NodeSqliteConnection::create($nodeFixtureConfig);
    $l1 = new ArrayCacheAdapter($nodeFixtureConfig->namespace);
    $l2 = new NodeSqliteCacheAdapter($connection, $nodeFixtureConfig->namespace);
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

test('node bulk reads fetch only L1 misses from SQLite and promote as one batch', function () use (&$nodeFixtureConfig): void {
    $connection = NodeSqliteConnection::create($nodeFixtureConfig);
    $l1 = new ArrayCacheAdapter($nodeFixtureConfig->namespace);
    $l2 = new NodeSqliteCacheAdapter($connection, $nodeFixtureConfig->namespace);
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

test('node tagged L1 hits use cached generation metadata', function () use (&$nodeFixtureConfig): void {
    $connection = NodeSqliteConnection::create($nodeFixtureConfig);
    $l1 = new ArrayCacheAdapter($nodeFixtureConfig->namespace);
    $l2 = new NodeSqliteCacheAdapter($connection, $nodeFixtureConfig->namespace);
    $cache = new Cache(new NodeCacheAdapter($l1, $l2, false));
    $cache->setTagged('tagged', 'value', ['products'], 300);
    $connection->prepare(
        "DELETE FROM cachelayer_node_entries WHERE namespace = ? AND cache_key = 'm:tag:products'",
    )->execute([$nodeFixtureConfig->namespace]);

    expect($cache->get('tagged'))->toBe('value');
    $l1->clear();
    expect($cache->get('tagged'))->toBeNull();
});

test('node never writes L1 when its authoritative L2 write fails', function () use (&$nodeFixtureConfig): void {
    $connection = NodeSqliteConnection::create($nodeFixtureConfig);
    $l1 = new ArrayCacheAdapter($nodeFixtureConfig->namespace);
    $l2 = new NodeSqliteCacheAdapter($connection, $nodeFixtureConfig->namespace);
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

test('node cache applies the complete cache policy from its configuration', function () use (&$nodeFixtureDirectory): void {
    $cache = NodeCache::create(new NodeCacheConfig(
        sqliteFile: $nodeFixtureDirectory . '/policy.sqlite',
        namespace: 'policy',
        apcuEnabled: false,
        options: new CacheOptions(
            integrityKey: 'node-policy-key',
            maxPayloadBytes: 1_048_576,
            failOpen: false,
        ),
    ));

    expect($cache->hasPayloadIntegrity())->toBeTrue()
        ->and($cache->isFailOpen())->toBeFalse()
        ->and($cache->set('scalar', 'value'))->toBeTrue()
        ->and($cache->get('scalar'))->toBe('value');
});

test('node disables a failed L1 before it can serve stale data', function () use (&$nodeFixtureConfig): void {
    $connection = NodeSqliteConnection::create($nodeFixtureConfig);
    $l1 = new class extends AbstractCacheAdapter {
        public bool $rejectWrites = false;

        /** @var array<string, mixed> */
        private array $values = [];

        #[\Override]
        public function clear(): bool
        {
            $this->values = [];

            return true;
        }

        #[\Override]
        public function deleteItem(string $key): bool
        {
            unset($this->values[$key]);

            return true;
        }

        #[\Override]
        public function deleteItems(array $keys): bool
        {
            foreach ($keys as $key) {
                unset($this->values[$key]);
            }

            return true;
        }

        #[\Override]
        public function getItem(string $key): CacheItem
        {
            return array_key_exists($key, $this->values)
                ? new CacheItem($this, $key, $this->values[$key], true)
                : new CacheItem($this, $key);
        }

        #[\Override]
        public function hasItem(string $key): bool
        {
            return array_key_exists($key, $this->values);
        }

        #[\Override]
        public function multiFetch(array $keys): array
        {
            $items = [];
            foreach ($keys as $key) {
                $items[$key] = $this->getItem($key);
            }

            return $items;
        }

        #[\Override]
        public function save(CacheItemInterface $item): bool
        {
            if ($this->rejectWrites || !$this->supportsItem($item)) {
                return false;
            }

            $this->values[$item->getKey()] = $item->get();

            return true;
        }

        #[\Override]
        public function saveItems(array $items): bool
        {
            if ($this->rejectWrites || !$this->supportsItems($items)) {
                return false;
            }

            foreach ($items as $item) {
                $this->values[$item->getKey()] = $item->get();
            }

            return true;
        }
    };
    $l2 = new NodeSqliteCacheAdapter($connection, $nodeFixtureConfig->namespace);
    $cache = new Cache(new NodeCacheAdapter($l1, $l2, true));

    expect($cache->set('coherent', 'old', 300))->toBeTrue();
    $l1->rejectWrites = true;

    expect($cache->set('coherent', 'new', 300))->toBeFalse()
        ->and($cache->get('coherent'))->toBe('new')
        ->and($cache->setMultiple(['one' => 1, 'two' => 2], 300))->toBeTrue()
        ->and($cache->getMultiple(['one', 'two']))->toBe(['one' => 1, 'two' => 2]);
});

test('expired rows remain outside the read path until bounded pruning', function () use (&$nodeFixtureConfig): void {
    $connection = NodeSqliteConnection::create($nodeFixtureConfig);
    $adapter = new NodeSqliteCacheAdapter($connection, $nodeFixtureConfig->namespace);
    $statement = $connection->prepare(
        'INSERT INTO cachelayer_node_entries (namespace, cache_key, payload, expires_at) VALUES (?, ?, ?, ?)',
    );
    $statement->execute([$nodeFixtureConfig->namespace, 'stale', 'invalid-payload', time() - 1]);

    expect($adapter->getItem('stale')->isHit())->toBeFalse()
        ->and(\Infocyph\CacheLayer\Tests\Support\CacheFixture::countRows($connection, "SELECT COUNT(*) FROM cachelayer_node_entries WHERE cache_key = 'stale'"))
        ->toBe(1)
        ->and((new NodeCachePruner($connection, $nodeFixtureConfig->namespace))->pruneExpired(1))
        ->toBe(1)
        ->and(\Infocyph\CacheLayer\Tests\Support\CacheFixture::countRows($connection, "SELECT COUNT(*) FROM cachelayer_node_entries WHERE cache_key = 'stale'"))
        ->toBe(0);
});

test('node cache configuration rejects invalid paths and timeouts', function (): void {
    expect(fn() => new NodeCacheConfig('', 'app'))->toThrow(NodeCacheConfigurationException::class)
        ->and(fn() => new NodeCacheConfig('/tmp/cache.sqlite', 'app', busyTimeoutMs: -1))
        ->toThrow(NodeCacheConfigurationException::class);
});

test('node APCu identity includes the SQLite store', function () use (&$nodeFixtureDirectory): void {
    expect(extension_loaded('apcu'))->toBeTrue()
        ->and(apcu_enabled())->toBeTrue();
    apcu_clear_cache();

    $first = NodeCache::create(new NodeCacheConfig(
        sqliteFile: $nodeFixtureDirectory . '/first.sqlite',
        namespace: 'shared.namespace',
        apcuEnabled: true,
    ));
    $second = NodeCache::create(new NodeCacheConfig(
        sqliteFile: $nodeFixtureDirectory . '/second.sqlite',
        namespace: 'shared.namespace',
        apcuEnabled: true,
    ));

    expect($first->set('shared', 'first'))->toBeTrue()
        ->and($second->get('shared'))->toBeNull()
        ->and($second->set('shared', 'second'))->toBeTrue()
        ->and($first->get('shared'))->toBe('first')
        ->and($second->get('shared'))->toBe('second');

    apcu_clear_cache();
});

test('node lock identity includes the SQLite store', function () use (&$nodeFixtureDirectory): void {
    $keys = [];
    $provider = new class ($keys) implements LockProviderInterface {
        public function __construct(private array &$keys) {}

        #[\Override]
        public function acquire(string $key, float $waitSeconds, float $leaseSeconds = 30.0): ?LockHandle
        {
            unset($waitSeconds);
            $this->keys[] = $key;

            return new LockHandle($key, bin2hex(random_bytes(16)), leaseSeconds: $leaseSeconds);
        }

        #[\Override]
        public function refresh(?LockHandle $handle, float $leaseSeconds): bool
        {
            return $handle instanceof LockHandle && $leaseSeconds > 0;
        }

        #[\Override]
        public function release(?LockHandle $handle): void {}
    };

    foreach (['first.sqlite', 'second.sqlite'] as $file) {
        $cache = NodeCache::create(new NodeCacheConfig(
            sqliteFile: $nodeFixtureDirectory . '/' . $file,
            namespace: 'shared.namespace',
            apcuEnabled: false,
            lockProvider: $provider,
        ));
        $cache->remember('same-key', static fn(): string => 'value', 30);
    }

    expect($keys)->toHaveCount(2)
        ->and($keys[0])->not->toBe($keys[1]);
});

test('node authority reflects whether an L1 cache can serve stale state', function () use (&$nodeFixtureDirectory): void {
    expect(extension_loaded('apcu'))->toBeTrue()
        ->and(apcu_enabled())->toBeTrue();
    apcu_clear_cache();

    $withL1 = NodeCache::create(new NodeCacheConfig(
        sqliteFile: $nodeFixtureDirectory . '/with-l1.sqlite',
        namespace: 'authority',
        apcuEnabled: true,
    ));
    $l2Only = NodeCache::create(new NodeCacheConfig(
        sqliteFile: $nodeFixtureDirectory . '/l2-only.sqlite',
        namespace: 'authority',
        apcuEnabled: false,
    ));

    expect($withL1->isAuthoritative())->toBeFalse()
        ->and($l2Only->isAuthoritative())->toBeTrue();

    apcu_clear_cache();
});

test('node SQLite adapter accepts associative PSR-6 delete key arrays', function () use (&$nodeFixtureConfig): void {
    $adapter = new NodeSqliteCacheAdapter(NodeSqliteConnection::create($nodeFixtureConfig), $nodeFixtureConfig->namespace);
    $cache = new Cache($adapter);
    expect($cache->setMultiple(['a' => 1, 'b' => 2, 'c' => 3]))->toBeTrue()
        ->and($adapter->deleteItems(['first' => 'a', 'second' => 'b']))->toBeTrue()
        ->and($cache->has('a'))->toBeFalse()
        ->and($cache->has('b'))->toBeFalse()
        ->and($cache->get('c'))->toBe(3);
});
