<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Benchmarks;

use Infocyph\CacheLayer\Memoize\Memoizer;
use PhpBench\Attributes as Bench;

/** @api PHPBench discovers this benchmark entry point through attributes. */
#[Bench\Iterations(5)]
#[Bench\Revs(5000)]
final class MemoizeBench
{
    public function setUp(): void
    {
        Memoizer::instance()->flush();
    }

    /**
     * @throws \ReflectionException
     * @throws \UnexpectedValueException
     * @throws \LogicException
     * @throws \OverflowException
     */
    #[Bench\BeforeMethods(['setUp'])]
    public function benchGlobalMemoizeHit(): int
    {
        $fn = static fn(int $v): int => $v * 2;
        $sum = 0;
        for ($i = 0; $i < 100; $i++) {
            $value = memoize($fn, [7]);
            if (!is_int($value)) {
                throw new \UnexpectedValueException('The benchmark expected an integer memoized result.');
            }
            $sum += $value;
        }

        return $sum;
    }

    /**
     * @throws \ReflectionException
     * @throws \InvalidArgumentException
     * @throws \UnexpectedValueException
     * @throws \LogicException
     * @throws \OverflowException
     */
    #[Bench\BeforeMethods(['setUp'])]
    public function benchObjectMemoizeHit(): int
    {
        $obj = new \stdClass();
        $fn = static fn(): int => 21;
        $sum = 0;
        for ($i = 0; $i < 100; $i++) {
            $value = remember($obj, $fn);
            if (!is_int($value)) {
                throw new \UnexpectedValueException('The benchmark expected an integer memoized result.');
            }
            $sum += $value;
        }

        return $sum;
    }

    /**
     * @throws \UnexpectedValueException
     * @throws \LogicException
     * @throws \OverflowException
     */
    #[Bench\BeforeMethods(['setUp'])]
    public function benchOnceCallSiteLookup(): int
    {
        $sum = 0;
        for ($index = 0; $index < 100; $index++) {
            $value = once(static fn(): int => 21);
            if (!is_int($value)) {
                throw new \UnexpectedValueException('The benchmark expected an integer memoized result.');
            }
            $sum += $value;
        }

        return $sum;
    }
}
