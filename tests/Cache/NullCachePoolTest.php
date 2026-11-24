<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cache\Cache;

$nullFixtureCache = Cache::nullStore();

beforeEach(function () use (&$nullFixtureCache): void {
    $nullFixtureCache = Cache::nullStore();
});

test('null adapter never stores data', function () use (&$nullFixtureCache): void {
    expect($nullFixtureCache->set('x', 10))->toBeTrue()
        ->and($nullFixtureCache->get('x'))->toBeNull()
        ->and($nullFixtureCache->hasItem('x'))->toBeFalse();
});

test('remember always recomputes with null adapter', function () use (&$nullFixtureCache): void {
    $calls = 0;

    $a = $nullFixtureCache->remember('k', function () use (&$calls): string {
        $calls++;

        return 'v';
    });
    $b = $nullFixtureCache->remember('k', function () use (&$calls) {
        $calls++;

        return 'v';
    });

    expect($a)->toBe('v')
        ->and($b)->toBe('v')
        ->and($calls)->toBe(2);
});
