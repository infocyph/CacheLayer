<?php

use Infocyph\CacheLayer\Cache\Cache;

beforeEach(function () {
    $this->cache = Cache::nullStore();
});

test('null adapter never stores data', function () {
    expect($this->cache->set('x', 10))->toBeTrue()
        ->and($this->cache->get('x'))->toBeNull()
        ->and($this->cache->hasItem('x'))->toBeFalse();
});

test('remember always recomputes with null adapter', function () {
    $calls = 0;

    $a = $this->cache->remember('k', function () use (&$calls) {
        $calls++;

        return 'v';
    });
    $b = $this->cache->remember('k', function () use (&$calls) {
        $calls++;

        return 'v';
    });

    expect($a)->toBe('v')
        ->and($b)->toBe('v')
        ->and($calls)->toBe(2);
});
