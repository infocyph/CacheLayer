<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Benchmarks;

use Infocyph\CacheLayer\Cache\Adapter\ArrayCacheAdapter;
use Infocyph\CacheLayer\Cache\Cache;
use PhpBench\Attributes as Bench;

#[Bench\Iterations(5)]
#[Bench\Revs(1)]
final class CacheBulkBench
{
    private Cache $cache;

    /** @var list<string> */
    private array $keys = [];

    private ?string $sqliteFile = null;

    /** @var array<string, int> */
    private array $values = [];

    /** @param array{size:int} $params */
    public function setUp(array $params): void
    {
        $this->cache = Cache::memory('bulk-bench');
        $this->keys = [];
        $this->values = [];
        for ($index = 0; $index < $params['size']; $index++) {
            $key = 'key.' . $index;
            $this->keys[] = $key;
            $this->values[$key] = $index;
        }
        $this->cache->setMultiple($this->values, 60);
    }

    /** @param array{size:int} $params */
    #[Bench\ParamProviders('provideSizes')]
    #[Bench\BeforeMethods('setUp')]
    public function benchMemoryDeleteMultiple(array $params): int
    {
        unset($params);

        return $this->cache->deleteMultiple($this->keys) ? count($this->keys) : 0;
    }

    /** @param array{size:int} $params */
    #[Bench\ParamProviders('provideSizes')]
    #[Bench\BeforeMethods('setUp')]
    public function benchMemoryGetMultiple(array $params): int
    {
        unset($params);

        return count($this->cache->getMultiple($this->keys));
    }

    /** @param array{size:int} $params */
    #[Bench\ParamProviders('provideSizes')]
    #[Bench\BeforeMethods('setUp')]
    public function benchMemorySetMultiple(array $params): int
    {
        unset($params);

        return $this->cache->setMultiple($this->values, 60) ? count($this->values) : 0;
    }

    public function benchSingleMemoryHit(): int
    {
        $cache = Cache::memory('single-memory');
        $cache->set('hot', 42);

        return (int) $cache->get('hot');
    }

    #[Bench\BeforeMethods('setUpSingleSqlite')]
    #[Bench\AfterMethods('tearDownSqlite')]
    public function benchSingleSqliteHit(): int
    {
        return (int) $this->cache->get('hot');
    }

    /** @param array{size:int} $params */
    #[Bench\ParamProviders('provideSizes')]
    #[Bench\BeforeMethods('setUpSqlite')]
    #[Bench\AfterMethods('tearDownSqlite')]
    public function benchSqliteGetMultiple(array $params): int
    {
        unset($params);

        return count($this->cache->getMultiple($this->keys));
    }

    /** @param array{size:int} $params */
    #[Bench\ParamProviders('provideSizes')]
    #[Bench\BeforeMethods('setUp')]
    public function benchTaggedBatchRead(array $params): int
    {
        unset($params);
        foreach ($this->values as $key => $value) {
            $this->cache->setTagged($key, $value, ['bulk']);
        }

        return count($this->cache->getMultiple($this->keys));
    }

    /** @param array{size:int} $params */
    #[Bench\ParamProviders('provideSizes')]
    public function benchTieredL1FullHit(array $params): int
    {
        $l1 = new ArrayCacheAdapter('tier-full-l1');
        $l2 = new ArrayCacheAdapter('tier-full-l2');
        $cache = Cache::tiered([$l1, $l2]);
        $keys = [];
        for ($index = 0; $index < $params['size']; $index++) {
            $key = 'tier-full.' . $index;
            $keys[] = $key;
            $l1->set($key, $index, 60);
        }

        return count($cache->getMultiple($keys));
    }

    /** @param array{size:int} $params */
    #[Bench\ParamProviders('provideSizes')]
    public function benchTieredL3Promotion(array $params): int
    {
        $l1 = new ArrayCacheAdapter('tier3-l1');
        $l2 = new ArrayCacheAdapter('tier3-l2');
        $l3 = new ArrayCacheAdapter('tier3-l3');
        $cache = Cache::tiered([$l1, $l2, $l3]);
        $keys = [];
        for ($index = 0; $index < $params['size']; $index++) {
            $key = 'tier3.' . $index;
            $keys[] = $key;
            $l3->set($key, $index, 60);
        }

        return count($cache->getMultiple($keys));
    }

    /** @param array{size:int} $params */
    #[Bench\ParamProviders('provideSizes')]
    public function benchTieredPartialHit(array $params): int
    {
        $l1 = new ArrayCacheAdapter('tier-l1');
        $l2 = new ArrayCacheAdapter('tier-l2');
        $cache = Cache::tiered([$l1, $l2]);
        $keys = [];
        for ($index = 0; $index < $params['size']; $index++) {
            $key = 'tier.' . $index;
            $keys[] = $key;
            $target = $index % 2 === 0 ? $l1 : $l2;
            $target->set($key, $index, 60);
        }

        return count($cache->getMultiple($keys));
    }

    public function provideSizes(): iterable
    {
        yield '10 keys' => ['size' => 10];
        yield '100 keys' => ['size' => 100];
        yield '1000 keys' => ['size' => 1000];
    }

    public function setUpSingleSqlite(): void
    {
        $this->sqliteFile = sys_get_temp_dir() . '/cachelayer-bench-' . uniqid() . '.sqlite';
        $this->cache = Cache::sqlite('sqlite-single-bench', $this->sqliteFile);
        $this->cache->set('hot', 42, 60);
    }

    /** @param array{size:int} $params */
    public function setUpSqlite(array $params): void
    {
        $this->sqliteFile = sys_get_temp_dir() . '/cachelayer-bench-' . uniqid() . '.sqlite';
        $this->cache = Cache::sqlite('sqlite-bench', $this->sqliteFile);
        $this->keys = [];
        $this->values = [];
        for ($index = 0; $index < $params['size']; $index++) {
            $key = 'sqlite.' . $index;
            $this->keys[] = $key;
            $this->values[$key] = $index;
        }
        $this->cache->setMultiple($this->values, 60);
    }

    public function tearDownSqlite(): void
    {
        $this->cache = Cache::memory('released');
        if ($this->sqliteFile !== null && is_file($this->sqliteFile)) {
            unlink($this->sqliteFile);
        }
        $this->sqliteFile = null;
    }
}
