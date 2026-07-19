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

it('once() caches by call site', function () {
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
        ->and($valueAgain)->toBe(1)
        ->and($counter)->toBe(1);
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
