<?php

use Infocyph\CacheLayer\Cache\Adapter\ArrayCacheAdapter;
use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\CacheLayer\Exceptions\CacheInvalidArgumentException;

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

test('tiered cache supports descriptor array tiers', function () {
    $cache = Cache::tiered([
        ['driver' => 'memory', 'namespace' => 'tiered-l1'],
        ['driver' => 'memory', 'namespace' => 'tiered-l2'],
    ]);

    expect($cache->set('k', 'v'))->toBeTrue()
        ->and($cache->get('k'))->toBe('v');
});

test('tiered cache can skip L1 write-through on save', function () {
    $l1 = new ArrayCacheAdapter('skip-l1');
    $l2 = new ArrayCacheAdapter('skip-l2');
    $cache = Cache::tiered([$l1, $l2], writeToL1: false);

    $cache->set('x', 'X');

    expect($l1->getItem('x')->isHit())->toBeFalse()
        ->and($l2->getItem('x')->isHit())->toBeTrue();

    expect($cache->get('x'))->toBe('X')
        ->and($l1->getItem('x')->isHit())->toBeTrue();
});

test('tiered cache rejects unsupported driver descriptors', function () {
    expect(fn() => Cache::tiered([['driver' => 'unknown-tier']]))
        ->toThrow(CacheInvalidArgumentException::class);
});
