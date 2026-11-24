<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Tests\Memoize;

use Infocyph\CacheLayer\Memoize\Memoizer;
use Closure;
use ReflectionClass;
use stdClass;
use WeakReference;
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

    $memoizer = memoize();
    if (!$memoizer instanceof Memoizer) {
        throw new \RuntimeException('Expected a memoizer instance.');
    }
    $stats = $memoizer->stats();
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
    $fn = function () use (&$counter): int {
        return ++$counter;
    };

    $first = remember($obj, $fn);
    $second = remember($obj, $fn);

    expect($first)->toBe(1)
        ->and($second)->toBe(1);
    $memoizer = memoize();
    if (!$memoizer instanceof Memoizer) {
        throw new \RuntimeException('Expected a memoizer instance.');
    }
    expect($memoizer->stats()['hits'])->toBe(1);
});

it('once() keeps distinct source lines independent', function () {
    $counter = 0;

    $value = (function () use (&$counter): mixed {
        return once(function () use (&$counter): int {
            return ++$counter;
        });
    })();

    $valueAgain = (function () use (&$counter): mixed {
        return once(function () use (&$counter): int {
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

        /**
         * @throws \RuntimeException
         */
        public function next(): int
        {
            $value = $this->memoize(__METHOD__, fn (): int => ++$this->count);
            if (!is_int($value)) {
                throw new \RuntimeException('Expected an integer memoized value.');
            }

            return $value;
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
    if (!$weakMap instanceof \WeakMap) {
        throw new \RuntimeException('Expected the object memoization map.');
    }
    $weakMap[$object] = $seed;

    $memoizer->get('strlen', ['fresh']);
    $memoizer->getFor($object, 'strlen', ['fresh']);

    $static = $staticCache->getValue($memoizer);
    $perObject = $weakMap[$object];
    if (!is_array($static)) {
        throw new \RuntimeException('Expected memoization buckets.');
    }
    expect($static)->toHaveCount(2_048)
        ->and($perObject)->toHaveCount(2_048)
        ->and(array_key_exists('seed-0', $static))->toBeFalse()
        ->and(array_key_exists('seed-0', $perObject))->toBeFalse();
});


it('same-line closures remain distinct', function () {
    [$first, $second] = [static fn(): string => 'first', static fn(): string => 'second'];

    expect(memoize($first))->toBe('first')
        ->and(memoize($second))->toBe('second')
        ->and(memoize($first))->toBe('first');
    $memoizer = memoize();
    if (!$memoizer instanceof Memoizer) {
        throw new \RuntimeException('Expected a memoizer instance.');
    }
    expect($memoizer->stats()['hits'])->toBe(1);
});

it('memoizer type-tags object string and resource parameters', function () {
    $object = new stdClass();
    $objectToken = 'stdClass#1';
    $resource = fopen('php://memory', 'rb');
    if (!is_resource($resource)) {
        throw new \RuntimeException('Unable to open resource fixture.');
    }

    $identity = static fn(mixed $value): string => get_debug_type($value);

    expect(memoize($identity, [$object]))->toBe('stdClass')
        ->and(memoize($identity, [$objectToken]))->toBe('string')
        ->and(memoize($identity, [$resource]))->toBe('resource (stream)')
        ->and(memoize($identity, ['res:stream#' . (int) $resource]))->toBe('string');

    fclose($resource);
});

it('per-object memoization does not retain collected owners', function () {
    $owner = new stdClass();
    $reference = WeakReference::create($owner);

    expect(remember($owner, static fn(): string => 'value'))->toBe('value');
    unset($owner);
    gc_collect_cycles();

    expect($reference->get())->toBeNull();
});


it('memoizer fingerprints recursive closure captures without traversing their graphs', function () {
    $recursive = [];
    $recursive['self'] = &$recursive;
    $arrayClosure = static fn(): int => count($recursive);

    $selfClosure = static function () use (&$selfClosure): string {
        return 'self:' . get_debug_type($selfClosure);
    };

    $left = static function () use (&$right): string {
        return 'right:' . get_debug_type($right);
    };
    $right = static function () use (&$left): string {
        return 'left:' . get_debug_type($left);
    };

    expect(memoize($arrayClosure))->toBe(1)
        ->and(memoize($arrayClosure))->toBe(1)
        ->and(memoize($selfClosure))->toBe('self:Closure')
        ->and(memoize($selfClosure))->toBe('self:Closure')
        ->and(memoize($left))->toBe('right:Closure')
        ->and(memoize($right))->toBe('left:Closure');
});

it('flushing an isolated memoizer does not invalidate another memoizer identity map', function () {
    $first = Memoizer::isolated();
    $second = Memoizer::isolated();
    $runs = 0;
    $callback = static function () use (&$runs): int {
        return ++$runs;
    };

    expect($first->get($callback))->toBe(1);
    $second->get(static fn(): string => 'other');
    $second->flush();

    expect($first->get($callback))->toBe(1)
        ->and($runs)->toBe(1);
});


it('once() caches null results without repeating callback side effects', function () {
    $runs = 0;
    $callback = static function () use (&$runs): mixed {
        $runs++;

        return null;
    };

    for ($attempt = 0; $attempt < 3; $attempt++) {
        expect(once($callback))->toBeNull();
    }

    expect($runs)->toBe(1);
});

it('memoizer distinguishes named argument bindings with the same values', function (): void {
    $memoizer = memoize();
    if (!$memoizer instanceof Memoizer) {
        throw new \RuntimeException('Expected a memoizer instance.');
    }
    $callback = static fn(int $left, int $right): int => $left - $right;
    $owner = new stdClass();
    expect($memoizer->get($callback, ['left' => 1, 'right' => 2]))->toBe(-1)
        ->and($memoizer->get($callback, ['right' => 1, 'left' => 2]))->toBe(1)
        ->and($memoizer->getFor($owner, $callback, ['left' => 1, 'right' => 2]))->toBe(-1)
        ->and($memoizer->getFor($owner, $callback, ['right' => 1, 'left' => 2]))->toBe(1)
        ->and($memoizer->get($callback, ['left' => 1, 'right' => 2]))->toBe(-1);
});
