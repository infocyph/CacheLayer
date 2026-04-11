<?php

use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\CacheLayer\Cache\Item\GenericCacheItem;

beforeEach(function () {
    $this->cache = Cache::memory('array-tests');
});

test('array adapter supports basic set/get/delete', function () {
    expect($this->cache->set('alpha', 1))->toBeTrue()
        ->and($this->cache->get('alpha'))->toBe(1)
        ->and($this->cache->delete('alpha'))->toBeTrue()
        ->and($this->cache->get('alpha'))->toBeNull();
});

test('array adapter getItem returns GenericCacheItem', function () {
    $item = $this->cache->getItem('x');

    expect($item)->toBeInstanceOf(GenericCacheItem::class)
        ->and($item->isHit())->toBeFalse();
});

test('array adapter honors ttl', function () {
    $this->cache->set('ttl', 'v', 1);
    usleep(2_000_000);

    expect($this->cache->get('ttl'))->toBeNull();
});

test('array adapter supports getItems', function () {
    $this->cache->set('a', 'A');
    $this->cache->set('b', 'B');

    $items = $this->cache->getItems(['a', 'b', 'c']);

    expect($items['a']->isHit())->toBeTrue()
        ->and($items['a']->get())->toBe('A')
        ->and($items['b']->get())->toBe('B')
        ->and($items['c']->isHit())->toBeFalse();
});
