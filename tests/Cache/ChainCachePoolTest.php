<?php

use Infocyph\CacheLayer\Cache\Adapter\ArrayCacheAdapter;
use Infocyph\CacheLayer\Cache\Cache;

beforeEach(function () {
    $this->l1 = new ArrayCacheAdapter('l1');
    $this->l2 = new ArrayCacheAdapter('l2');
    $this->cache = Cache::chain([$this->l1, $this->l2]);
});

test('chain adapter writes through all pools', function () {
    $this->cache->set('k', 'value');

    expect($this->l1->getItem('k')->isHit())->toBeTrue()
        ->and($this->l2->getItem('k')->isHit())->toBeTrue();
});

test('chain adapter promotes value from lower tier to upper tier', function () {
    $item = $this->l2->getItem('promote');
    $item->set('from-l2')->save();

    expect($this->l1->getItem('promote')->isHit())->toBeFalse();

    expect($this->cache->get('promote'))->toBe('from-l2')
        ->and($this->l1->getItem('promote')->isHit())->toBeTrue();
});
