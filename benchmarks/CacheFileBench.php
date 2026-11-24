<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Benchmarks;

use Infocyph\CacheLayer\Cache\Cache;
use PhpBench\Attributes as Bench;

/** @api PHPBench discovers this benchmark entry point through attributes. */
#[Bench\Iterations(5)]
#[Bench\Revs(500)]
final class CacheFileBench
{
    private ?Cache $cache = null;

    private string $dir = '';

    /**
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws \Infocyph\CacheLayer\Exceptions\CacheBackendException
     */
    public function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/cachelayer_bench_' . uniqid();
        $this->cache = Cache::file('bench', $this->dir);
        $this->cache->set('hot', 1, 60);
    }

    /**
     * @throws \UnexpectedValueException
     */
    public function tearDown(): void
    {
        if (!is_dir($this->dir)) {
            return;
        }

        $it = new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS);
        $rim = new \RecursiveIteratorIterator($it, \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($rim as $file) {
            if (!$file instanceof \SplFileInfo) {
                throw new \UnexpectedValueException('Unexpected filesystem iterator value.');
            }
            $path = $file->getRealPath();
            if ($path === false) {
                continue;
            }

            if ($file->isDir() && is_dir($path)) {
                rmdir($path);
            } elseif (is_file($path)) {
                unlink($path);
            }
        }

        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }
    }

    /**
     * @throws \Infocyph\CacheLayer\Exceptions\CacheInvalidArgumentException
     * @throws \Psr\Cache\InvalidArgumentException
     * @throws \LogicException
     * @throws \UnexpectedValueException
     * @throws \Infocyph\CacheLayer\Exceptions\CacheBackendException
     * @throws \Psr\SimpleCache\InvalidArgumentException
     */
    #[Bench\BeforeMethods(['setUp'])]
    #[Bench\AfterMethods(['tearDown'])]
    public function benchRememberMissThenHit(): int
    {
        $cache = $this->cache ?? throw new \LogicException('Benchmark setup did not run.');

        $sum = 0;
        for ($i = 0; $i < 100; $i++) {
            $value = $cache->remember('remember-key', static fn(): int => 42, 30);
            if (!is_int($value)) {
                throw new \UnexpectedValueException('The benchmark expected an integer cache hit.');
            }
            $sum += $value;
        }

        return $sum;
    }

    /**
     * @throws \Psr\Cache\InvalidArgumentException
     * @throws \LogicException
     * @throws \UnexpectedValueException
     * @throws \Infocyph\CacheLayer\Exceptions\CacheBackendException
     */
    #[Bench\BeforeMethods(['setUp'])]
    #[Bench\AfterMethods(['tearDown'])]
    public function benchSingletonGetHotPath(): int
    {
        $cache = $this->cache ?? throw new \LogicException('Benchmark setup did not run.');

        $sum = 0;
        for ($i = 0; $i < 100; $i++) {
            $value = $cache->get('hot', 0);
            if (!is_int($value)) {
                throw new \UnexpectedValueException('The benchmark expected an integer cache hit.');
            }
            $sum += $value;
        }

        return $sum;
    }

    /**
     * @throws \Infocyph\CacheLayer\Exceptions\CacheInvalidArgumentException
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws \Psr\Cache\InvalidArgumentException
     * @throws \LogicException
     * @throws \UnexpectedValueException
     * @throws \Infocyph\CacheLayer\Exceptions\CacheBackendException
     */
    #[Bench\BeforeMethods(['setUp'])]
    #[Bench\AfterMethods(['tearDown'])]
    public function benchTaggedSetAndInvalidate(): int
    {
        $cache = $this->cache ?? throw new \LogicException('Benchmark setup did not run.');

        $checksum = 0;
        for ($i = 0; $i < 50; $i++) {
            $key = 'tagged-' . $i;
            $cache->setTagged($key, $i, ['bench-tag']);
            $value = $cache->get($key, 0);
            if (!is_int($value)) {
                throw new \UnexpectedValueException('The benchmark expected an integer tagged hit.');
            }
            $checksum += $value;
        }

        $cache->invalidateTag('bench-tag');
        $checksum += $cache->get('tagged-0') === null ? 1 : 0;

        return $checksum;
    }
}
