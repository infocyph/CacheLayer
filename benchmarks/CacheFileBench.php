<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Benchmarks;

use Infocyph\CacheLayer\Cache\Cache;
use PhpBench\Attributes as Bench;

#[Bench\Iterations(5)]
#[Bench\Revs(500)]
final class CacheFileBench
{
    private Cache $cache;
    private string $dir;

    public function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/cachelayer_bench_' . uniqid();
        $this->cache = Cache::file('bench', $this->dir);
        $this->cache->set('hot', 1, 60);
    }

    public function tearDown(): void
    {
        if (!is_dir($this->dir)) {
            return;
        }

        $it = new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS);
        $rim = new \RecursiveIteratorIterator($it, \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($rim as $file) {
            $path = $file->getRealPath();
            if ($path === false) {
                continue;
            }

            $file->isDir() ? @rmdir($path) : @unlink($path);
        }

        @rmdir($this->dir);
    }

    #[Bench\BeforeMethods(['setUp'])]
    #[Bench\AfterMethods(['tearDown'])]
    public function benchRememberMissThenHit(): int
    {
        $sum = 0;
        for ($i = 0; $i < 100; $i++) {
            $sum += (int) $this->cache->remember('remember-key', fn() => 42, 30);
        }

        return $sum;
    }

    #[Bench\BeforeMethods(['setUp'])]
    #[Bench\AfterMethods(['tearDown'])]
    public function benchSingletonGetHotPath(): int
    {
        $sum = 0;
        for ($i = 0; $i < 100; $i++) {
            $sum += (int) $this->cache->get('hot', 0);
        }

        return $sum;
    }

    #[Bench\BeforeMethods(['setUp'])]
    #[Bench\AfterMethods(['tearDown'])]
    public function benchTaggedSetAndInvalidate(): int
    {
        $checksum = 0;
        for ($i = 0; $i < 50; $i++) {
            $key = 'tagged-' . $i;
            $this->cache->setTagged($key, $i, ['bench-tag']);
            $checksum += (int) ($this->cache->get($key, 0));
        }

        $this->cache->invalidateTag('bench-tag');
        $checksum += $this->cache->get('tagged-0') === null ? 1 : 0;

        return $checksum;
    }
}
