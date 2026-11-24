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

/** @api PHPBench discovers this benchmark entry point through attributes. */
#[Bench\Iterations(5)]
#[Bench\Revs(1)]
final class NodeCacheBench
{
    private ?Cache $cache = null;

    private string $directory = '';

    /** @var list<string> */
    private array $keys = [];

    private string $sqliteFile = '';

    /**
     * @throws \Infocyph\CacheLayer\Exceptions\CacheInvalidArgumentException
     * @throws \LogicException
     */
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

    /**
     * @throws \Psr\Cache\InvalidArgumentException
     * @throws \LogicException
     * @throws \Infocyph\CacheLayer\Exceptions\CacheBackendException
     */
    #[Bench\BeforeMethods('setUpFullHit')]
    #[Bench\AfterMethods('tearDown')]
    public function benchNodeL1FullHit(): int
    {
        $cache = $this->cache ?? throw new \LogicException('Benchmark setup did not run.');

        return iterator_count($cache->getMultiple($this->keys));
    }

    /**
     * @throws \Psr\Cache\InvalidArgumentException
     * @throws \LogicException
     * @throws \Infocyph\CacheLayer\Exceptions\CacheBackendException
     */
    #[Bench\BeforeMethods('setUpMixedHit')]
    #[Bench\AfterMethods('tearDown')]
    public function benchNodeMixedL1SqliteHit(): int
    {
        $cache = $this->cache ?? throw new \LogicException('Benchmark setup did not run.');

        return iterator_count($cache->getMultiple($this->keys));
    }

    /**
     * @throws \RuntimeException
     * @throws \Infocyph\CacheLayer\Node\Exception\NodeCacheConfigurationException
     * @throws \Infocyph\CacheLayer\Exceptions\CacheInvalidArgumentException
     * @throws \Psr\Cache\InvalidArgumentException
     * @throws \LogicException
     */
    public function setUpFullHit(): void
    {
        [$this->cache, $l1] = $this->createNodeCache();
        unset($l1);
        $this->seed();
    }

    /**
     * @throws \Psr\Cache\InvalidArgumentException
     * @throws \RuntimeException
     * @throws \Infocyph\CacheLayer\Node\Exception\NodeCacheConfigurationException
     * @throws \LogicException
     */
    public function setUpMixedHit(): void
    {
        [$this->cache, $l1] = $this->createNodeCache();
        $this->seed();
        $l1->deleteItems(array_values(array_filter(
            $this->keys,
            static fn(string $key): bool => ((int) substr($key, strlen('node.'))) % 2 !== 0,
        )));
    }

    /**
     * @return array{Cache, ArrayCacheAdapter}
     * @throws \RuntimeException
     * @throws \Infocyph\CacheLayer\Node\Exception\NodeCacheConfigurationException
     * @throws \Infocyph\CacheLayer\Exceptions\CacheInvalidArgumentException
     * @throws \LogicException
     */
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

    /**
     * @throws \Psr\Cache\InvalidArgumentException
     * @throws \LogicException
     * @throws \Infocyph\CacheLayer\Exceptions\CacheBackendException
     */
    private function seed(): void
    {
        $values = [];
        $this->keys = [];
        for ($index = 0; $index < 100; $index++) {
            $key = 'node.' . $index;
            $this->keys[] = $key;
            $values[$key] = $index;
        }
        $cache = $this->cache ?? throw new \LogicException('Benchmark setup did not run.');
        $cache->setMultiple($values, 60);
    }
}
