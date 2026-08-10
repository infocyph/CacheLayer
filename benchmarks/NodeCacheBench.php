<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Benchmarks;

use Infocyph\CacheLayer\Cache\Adapter\ArrayCacheAdapter;
use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\CacheLayer\Node\Adapter\NodeCacheAdapter;
use Infocyph\CacheLayer\Node\Adapter\NodeSqliteCacheAdapter;
use Infocyph\CacheLayer\Node\Connection\NodeSqliteConnection;
use Infocyph\CacheLayer\Node\NodeCacheConfig;
use PhpBench\Attributes as Bench;

#[Bench\Iterations(5)]
#[Bench\Revs(1)]
final class NodeCacheBench
{
    private Cache $cache;

    private string $directory;

    /** @var list<string> */
    private array $keys = [];

    private string $sqliteFile;

    public function tearDown(): void
    {
        $this->cache = Cache::memory('node-bench-released');
        foreach ([$this->sqliteFile, $this->sqliteFile . '-shm', $this->sqliteFile . '-wal'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    #[Bench\BeforeMethods('setUpFullHit')]
    #[Bench\AfterMethods('tearDown')]
    public function benchNodeL1FullHit(): int
    {
        return count($this->cache->getMultiple($this->keys));
    }

    #[Bench\BeforeMethods('setUpMixedHit')]
    #[Bench\AfterMethods('tearDown')]
    public function benchNodeMixedL1SqliteHit(): int
    {
        return count($this->cache->getMultiple($this->keys));
    }

    public function setUpFullHit(): void
    {
        [$this->cache, $l1] = $this->createNodeCache();
        unset($l1);
        $this->seed();
    }

    public function setUpMixedHit(): void
    {
        [$this->cache, $l1] = $this->createNodeCache();
        $this->seed();
        $l1->deleteItems(array_values(array_filter(
            $this->keys,
            static fn(string $key): bool => ((int) substr($key, strrpos($key, '.') + 1)) % 2 !== 0,
        )));
    }

    /** @return array{Cache, ArrayCacheAdapter} */
    private function createNodeCache(): array
    {
        $this->directory = sys_get_temp_dir() . '/cachelayer-node-bench-' . uniqid();
        if (!mkdir($this->directory, 0700) && !is_dir($this->directory)) {
            throw new \RuntimeException('Unable to create the Node benchmark directory.');
        }
        $this->sqliteFile = $this->directory . '/cache.sqlite';
        $namespace = 'node-bench';
        $config = new NodeCacheConfig($this->sqliteFile, $namespace, apcuEnabled: false);
        $l1 = new ArrayCacheAdapter($namespace);
        $l2 = new NodeSqliteCacheAdapter(NodeSqliteConnection::create($config), $namespace);

        return [new Cache(new NodeCacheAdapter($l1, $l2, false)), $l1];
    }

    private function seed(): void
    {
        $values = [];
        $this->keys = [];
        for ($index = 0; $index < 100; $index++) {
            $key = 'node.' . $index;
            $this->keys[] = $key;
            $values[$key] = $index;
        }
        $this->cache->setMultiple($values, 60);
    }
}
