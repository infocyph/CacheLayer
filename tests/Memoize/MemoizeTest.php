<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Memoize\Memoizer;
use Infocyph\CacheLayer\Memoize\MemoizeTrait;
use Infocyph\CacheLayer\Memoize\OnceMemoizer;

beforeEach(function () {
    Memoizer::instance()->flush();
    OnceMemoizer::instance()->flush();
});

it('memoize() returns Memoizer when called without args', function () {
    $memoizer = memoize();
    expect($memoizer)->toBeInstanceOf(Memoizer::class);
});

it('memoize() caches global callables', function () {
    $fn = fn (int $x): int => $x + 1;

    $a = memoize($fn, [1]);
    $b = memoize($fn, [1]);

    expect($a)->toBe(2)->and($b)->toBe(2);

    $stats = memoize()->stats();
    expect($stats)->toMatchArray([
        'hits' => 1,
        'misses' => 1,
        'total' => 2,
    ]);
});

it('remember() returns Memoizer when called with no object', function () {
    $memoizer = remember();
    expect($memoizer)->toBeInstanceOf(Memoizer::class);
});

it('remember() caches per-instance callables', function () {
    $obj = new stdClass;
    $counter = 0;
    $fn = function () use (&$counter) {
        return ++$counter;
    };

    $first = remember($obj, $fn);
    $second = remember($obj, $fn);

    expect($first)->toBe(1)
        ->and($second)->toBe(1)
        ->and(memoize()->stats()['hits'])->toBe(1);
});

it('once() keeps distinct source lines independent', function () {
    $counter = 0;

    $value = (function () use (&$counter) {
        return once(function () use (&$counter) {
            return ++$counter;
        });
    })();

    $valueAgain = (function () use (&$counter) {
        return once(function () use (&$counter) {
            return ++$counter;
        });
    })();

    expect($value)->toBe(1)
        ->and($valueAgain)->toBe(2)
        ->and($counter)->toBe(2);
});

it('memoizer distinguishes closure captures and object instances', function () {
    $make = static fn(int $offset): Closure => fn(int $value): int => $value + $offset;
    $first = $make(10);
    $second = $make(20);
    $service = static fn(int $offset): object => new class($offset) {
        public function __construct(private int $offset) {}

        public function calculate(int $value): int
        {
            return $value + $this->offset;
        }
    };
    $serviceA = $service(10);
    $serviceB = $service(20);

    expect(memoize($first, [1]))->toBe(11)
        ->and(memoize($second, [1]))->toBe(21)
        ->and(memoize([$serviceA, 'calculate'], [1]))->toBe(11)
        ->and(memoize([$serviceB, 'calculate'], [1]))->toBe(21);
});

it('memoizer distinguishes separate reference captures from the same source', function () {
    $first = 10;
    $second = 10;
    $factory = static function (int &$capture): Closure {
        return static function () use (&$capture): int {
            return $capture;
        };
    };

    expect(memoize($factory($first)))->toBe(10);
    $second = 20;

    expect(memoize($factory($second)))->toBe(20);
});

it('flush_memoizers resets process lifetime memoization', function () {
    $runs = 0;
    $loader = function () use (&$runs): int {
        return ++$runs;
    };

    expect(memoize($loader))->toBe(1)
        ->and(memoize($loader))->toBe(1);
    flush_memoizers();
    expect(memoize($loader))->toBe(2);
});

it('memoize trait caches values within object', function () {
    $inst = new class
    {
        use MemoizeTrait;

        public int $count = 0;

        public function next(): int
        {
            return $this->memoize(__METHOD__, fn () => ++$this->count);
        }
    };

    expect($inst->next())->toBe(1)
        ->and($inst->next())->toBe(1)
        ->and($inst->count)->toBe(1);
});

it('keeps global and per-object memoization buckets bounded', function () {
    $memoizer = Memoizer::instance();
    $reflection = new ReflectionClass($memoizer);
    $staticCache = $reflection->getProperty('staticCache');
    $objectCache = $reflection->getProperty('objectCache');
    $seed = [];
    for ($index = 0; $index < 2_048; $index++) {
        $seed['seed-' . $index] = $index;
    }
    $staticCache->setValue($memoizer, $seed);

    $object = new stdClass();
    $weakMap = $objectCache->getValue($memoizer);
    $weakMap[$object] = $seed;

    $memoizer->get('strlen', ['fresh']);
    $memoizer->getFor($object, 'strlen', ['fresh']);

    expect($staticCache->getValue($memoizer))->toHaveCount(2_048)
        ->and($weakMap[$object])->toHaveCount(2_048)
        ->and(array_key_exists('seed-0', $staticCache->getValue($memoizer)))->toBeFalse()
        ->and(array_key_exists('seed-0', $weakMap[$object]))->toBeFalse();
});
