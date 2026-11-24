<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Benchmarks;

use Infocyph\CacheLayer\Cache\Adapter\ArrayCacheAdapter;
use Infocyph\CacheLayer\Cache\Cache;
use PhpBench\Attributes as Bench;

/** @api PHPBench discovers this benchmark entry point through attributes. */
#[Bench\Iterations(5)]
#[Bench\Revs(1)]
final class CacheBulkBench
{
    private ?Cache $cache = null;

    /** @var list<string> */
    private array $keys = [];

    private ?string $sqliteFile = null;

    /** @var array<string, int> */
    private array $values = [];

    /**
     * @param array{size:int} $params
     * @throws \Psr\Cache\InvalidArgumentException
     * @throws \Infocyph\CacheLayer\Exceptions\CacheBackendException
     * @throws \LogicException
     */
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

    /**
     * @param array{size:int} $params
     * @throws \Psr\Cache\InvalidArgumentException
     * @throws \LogicException
     * @throws \Infocyph\CacheLayer\Exceptions\CacheBackendException
     */
    #[Bench\ParamProviders('provideSizes')]
    #[Bench\BeforeMethods('setUp')]
    public function benchMemoryDeleteMultiple(array $params): int
    {
        $cache = $this->cache ?? throw new \LogicException('Benchmark setup did not run.');

        unset($params);

        return $cache->deleteMultiple($this->keys) ? count($this->keys) : 0;
    }

    /**
     * @param array{size:int} $params
     * @throws \Psr\Cache\InvalidArgumentException
     * @throws \LogicException
     * @throws \Infocyph\CacheLayer\Exceptions\CacheBackendException
     */
    #[Bench\ParamProviders('provideSizes')]
    #[Bench\BeforeMethods('setUp')]
    public function benchMemoryGetMultiple(array $params): int
    {
        $cache = $this->cache ?? throw new \LogicException('Benchmark setup did not run.');

        unset($params);

        return iterator_count($cache->getMultiple($this->keys));
    }

    /**
     * @param array{size:int} $params
     * @throws \Psr\Cache\InvalidArgumentException
     * @throws \LogicException
     * @throws \Infocyph\CacheLayer\Exceptions\CacheBackendException
     */
    #[Bench\ParamProviders('provideSizes')]
    #[Bench\BeforeMethods('setUp')]
    public function benchMemorySetMultiple(array $params): int
    {
        $cache = $this->cache ?? throw new \LogicException('Benchmark setup did not run.');

        unset($params);

        return $cache->setMultiple($this->values, 60) ? count($this->values) : 0;
    }

    /**
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws \Psr\Cache\InvalidArgumentException
     * @throws \UnexpectedValueException
     * @throws \Infocyph\CacheLayer\Exceptions\CacheBackendException
     * @throws \LogicException
     */
    public function benchSingleMemoryHit(): int
    {
        $cache = Cache::memory('single-memory');
        $cache->set('hot', 42);

        $value = $cache->get('hot');
        if (!is_int($value)) {
            throw new \UnexpectedValueException('The benchmark expected an integer cache hit.');
        }

        return $value;
    }

    /**
     * @throws \Psr\Cache\InvalidArgumentException
     * @throws \LogicException
     * @throws \UnexpectedValueException
     * @throws \Infocyph\CacheLayer\Exceptions\CacheBackendException
     */
    #[Bench\BeforeMethods('setUpSingleSqlite')]
    #[Bench\AfterMethods('tearDownSqlite')]
    public function benchSingleSqliteHit(): int
    {
        $cache = $this->cache ?? throw new \LogicException('Benchmark setup did not run.');

        $value = $cache->get('hot');
        if (!is_int($value)) {
            throw new \UnexpectedValueException('The benchmark expected an integer cache hit.');
        }

        return $value;
    }

    /**
     * @param array{size:int} $params
     * @throws \Psr\Cache\InvalidArgumentException
     * @throws \LogicException
     * @throws \Infocyph\CacheLayer\Exceptions\CacheBackendException
     */
    #[Bench\ParamProviders('provideSizes')]
    #[Bench\BeforeMethods('setUpSqlite')]
    #[Bench\AfterMethods('tearDownSqlite')]
    public function benchSqliteGetMultiple(array $params): int
    {
        $cache = $this->cache ?? throw new \LogicException('Benchmark setup did not run.');

        unset($params);

        return iterator_count($cache->getMultiple($this->keys));
    }

    /**
     * @param array{size:int} $params
     * @throws \Infocyph\CacheLayer\Exceptions\CacheInvalidArgumentException
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws \Psr\Cache\InvalidArgumentException
     * @throws \LogicException
     * @throws \Infocyph\CacheLayer\Exceptions\CacheBackendException
     */
    #[Bench\ParamProviders('provideSizes')]
    #[Bench\BeforeMethods('setUp')]
    public function benchTaggedBatchRead(array $params): int
    {
        $cache = $this->cache ?? throw new \LogicException('Benchmark setup did not run.');

        unset($params);
        foreach ($this->values as $key => $value) {
            $cache->setTagged($key, $value, ['bulk']);
        }

        return iterator_count($cache->getMultiple($this->keys));
    }

    /**
     * @param array{size:int} $params
     * @throws \Infocyph\CacheLayer\Exceptions\CacheInvalidArgumentException
     * @throws \Psr\Cache\InvalidArgumentException
     * @throws \InvalidArgumentException
     * @throws \Infocyph\CacheLayer\Exceptions\CacheBackendException
     * @throws \LogicException
     */
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
            $l1->save($l1->createItem($key)->set($index)->expiresAfter(60));
        }

        return iterator_count($cache->getMultiple($keys));
    }

    /**
     * @param array{size:int} $params
     * @throws \Infocyph\CacheLayer\Exceptions\CacheInvalidArgumentException
     * @throws \Psr\Cache\InvalidArgumentException
     * @throws \InvalidArgumentException
     * @throws \Infocyph\CacheLayer\Exceptions\CacheBackendException
     * @throws \LogicException
     */
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
            $l3->save($l3->createItem($key)->set($index)->expiresAfter(60));
        }

        return iterator_count($cache->getMultiple($keys));
    }

    /**
     * @param array{size:int} $params
     * @throws \Infocyph\CacheLayer\Exceptions\CacheInvalidArgumentException
     * @throws \Psr\Cache\InvalidArgumentException
     * @throws \InvalidArgumentException
     * @throws \Infocyph\CacheLayer\Exceptions\CacheBackendException
     * @throws \LogicException
     */
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
            $target->save($target->createItem($key)->set($index)->expiresAfter(60));
        }

        return iterator_count($cache->getMultiple($keys));
    }

    /** @return iterable<string, array{size:int}> */
    public function provideSizes(): iterable
    {
        yield '1 key' => ['size' => 1];
        yield '10 keys' => ['size' => 10];
        yield '100 keys' => ['size' => 100];
        yield '1000 keys' => ['size' => 1000];
    }

    /**
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws \Infocyph\CacheLayer\Exceptions\CacheBackendException
     * @throws \RuntimeException
     * @throws \Infocyph\CacheLayer\Cache\Lock\UnsupportedPdoLockDriver
     * @throws \LogicException
     */
    public function setUpSingleSqlite(): void
    {
        $this->sqliteFile = sys_get_temp_dir() . '/cachelayer-bench-' . uniqid() . '.sqlite';
        $this->cache = Cache::sqlite('sqlite-single-bench', $this->sqliteFile);
        $this->cache->set('hot', 42, 60);
    }

    /**
     * @param array{size:int} $params
     * @throws \Psr\Cache\InvalidArgumentException
     * @throws \Infocyph\CacheLayer\Exceptions\CacheBackendException
     * @throws \RuntimeException
     * @throws \Infocyph\CacheLayer\Cache\Lock\UnsupportedPdoLockDriver
     * @throws \LogicException
     */
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

    /**
     * @throws \Infocyph\CacheLayer\Exceptions\CacheInvalidArgumentException
     * @throws \LogicException
     */
    public function tearDownSqlite(): void
    {
        $this->cache = Cache::memory('released');
        if ($this->sqliteFile !== null && is_file($this->sqliteFile)) {
            unlink($this->sqliteFile);
        }
        $this->sqliteFile = null;
    }
}
