<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Memoize\OnceMemoizer;

beforeEach(function () {
    OnceMemoizer::instance()->flush();
});

it('caches repeated calls from the same caller', function () {
    $memoizer = OnceMemoizer::instance();
    $counter = 0;

    $run = function () use ($memoizer, &$counter): int {
        return $memoizer->once(function () use (&$counter) {
            return ++$counter;
        });
    };

    $first = $run();
    $second = $run();

    expect($first)->toBe(1)
        ->and($second)->toBe(1)
        ->and($counter)->toBe(1);
});

it('isolates cache entries by caller context', function () {
    $counter = 0;

    $caller = new class
    {
        public function one(int &$counter): int
        {
            return OnceMemoizer::instance()->once(function () use (&$counter) {
                return ++$counter;
            });
        }

        public function two(int &$counter): int
        {
            return OnceMemoizer::instance()->once(function () use (&$counter) {
                return ++$counter;
            });
        }
    };

    $a1 = $caller->one($counter);
    $a2 = $caller->one($counter);
    $b1 = $caller->two($counter);
    $b2 = $caller->two($counter);

    expect($a1)->toBe(1)
        ->and($a2)->toBe(1)
        ->and($b1)->toBe(2)
        ->and($b2)->toBe(2)
        ->and($counter)->toBe(2);
});

it('keeps internal cache bounded', function () {
    $memoizer = OnceMemoizer::instance();
    $ref = new ReflectionClass(OnceMemoizer::class);
    $cacheProp = $ref->getProperty('cache');
    $orderProp = $ref->getProperty('order');

    $seedCache = [];
    $seedOrder = [];
    for ($i = 0; $i < 2048; $i++) {
        $key = 'seed-'.$i;
        $seedCache[$key] = $i;
        $seedOrder[] = $key;
    }

    $cacheProp->setValue($memoizer, $seedCache);
    $orderProp->setValue($memoizer, $seedOrder);

    $value = (function () use ($memoizer): string {
        return $memoizer->once(static fn (): string => 'fresh');
    })();

    $cache = $cacheProp->getValue($memoizer);
    $order = $orderProp->getValue($memoizer);

    expect($value)->toBe('fresh')
        ->and(count($cache))->toBe(2048)
        ->and(count($order))->toBe(2048)
        ->and(array_key_exists('seed-0', $cache))->toBeFalse();
});

it('flush resets cache state', function () {
    $memoizer = OnceMemoizer::instance();
    $counter = 0;

    $run = function () use (&$counter, $memoizer): int {
        return $memoizer->once(function () use (&$counter) {
            return ++$counter;
        });
    };

    $first = $run();
    $second = $run();
    $memoizer->flush();
    $third = $run();

    expect($first)->toBe(1)
        ->and($second)->toBe(1)
        ->and($third)->toBe(2)
        ->and($counter)->toBe(2);
});
