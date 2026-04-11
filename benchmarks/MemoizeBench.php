<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Benchmarks;

use Infocyph\CacheLayer\Memoize\Memoizer;
use PhpBench\Attributes as Bench;

#[Bench\Iterations(5)]
#[Bench\Revs(5000)]
final class MemoizeBench
{
    public function setUp(): void
    {
        Memoizer::instance()->flush();
    }
    #[Bench\BeforeMethods(['setUp'])]
    public function benchGlobalMemoizeHit(): int
    {
        $fn = static fn(int $v): int => $v * 2;
        $sum = 0;
        for ($i = 0; $i < 100; $i++) {
            $sum += memoize($fn, [7]);
        }

        return $sum;
    }

    #[Bench\BeforeMethods(['setUp'])]
    public function benchObjectMemoizeHit(): int
    {
        $obj = new \stdClass();
        $fn = static fn(): int => 21;
        $sum = 0;
        for ($i = 0; $i < 100; $i++) {
            $sum += remember($obj, $fn);
        }

        return $sum;
    }
}
