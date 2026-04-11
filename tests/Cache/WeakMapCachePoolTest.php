<?php

use Infocyph\CacheLayer\Cache\Cache;

beforeEach(function () {
    $this->cache = Cache::weakMap('weak-tests');
});

test('weak map adapter stores scalar values', function () {
    $this->cache->set('a', 123);

    expect($this->cache->get('a'))->toBe(123)
        ->and($this->cache->hasItem('a'))->toBeTrue();
});

test('weak map adapter returns same object while strongly referenced', function () {
    $obj = new stdClass();
    $obj->name = 'cache-object';

    $this->cache->set('obj', $obj);
    $restored = $this->cache->get('obj');

    expect($restored)->toBe($obj)
        ->and($restored->name)->toBe('cache-object');
});
