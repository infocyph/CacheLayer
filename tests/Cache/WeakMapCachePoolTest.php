<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\CacheLayer\Cache\CacheOptions;

$weakmapFixtureCache = Cache::nullStore();

beforeEach(function () use (&$weakmapFixtureCache): void {
    $weakmapFixtureCache = Cache::weakMap('weak-tests', new CacheOptions(allowObjects: true));
});

test('weak map adapter stores scalar values', function () use (&$weakmapFixtureCache): void {
    $weakmapFixtureCache->set('a', 123);

    expect($weakmapFixtureCache->get('a'))->toBe(123)
        ->and($weakmapFixtureCache->hasItem('a'))->toBeTrue();
});

test('weak map adapter returns same object while strongly referenced', function () use (&$weakmapFixtureCache): void {
    $obj = new stdClass;
    $obj->name = 'cache-object';

    $weakmapFixtureCache->set('obj', $obj);
    $restored = $weakmapFixtureCache->get('obj');
    assert($restored instanceof stdClass);

    expect($restored)->toBe($obj)
        ->and($restored->name)->toBe('cache-object');
});
