<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cache\Adapter\ArrayCacheAdapter;
use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\CacheLayer\Exceptions\CacheInvalidArgumentException;

beforeEach(function () {
    $this->l1 = new ArrayCacheAdapter('l1');
    $this->l2 = new ArrayCacheAdapter('l2');
    $this->cache = Cache::tiered([$this->l1, $this->l2]);
});

test('tiered adapter writes through all pools', function () {
    $this->cache->set('k', 'value');

    expect($this->l1->getItem('k')->isHit())->toBeTrue()
        ->and($this->l2->getItem('k')->isHit())->toBeTrue();
});

test('tiered adapter promotes value from lower tier to upper tier', function () {
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

test('tiered tag validation follows the authoritative last tier', function () {
    $l1 = new ArrayCacheAdapter('tier-disagreement');
    $l2 = new ArrayCacheAdapter('tier-disagreement');
    $cache = Cache::tiered([$l1, $l2]);
    $cache->setTagged('tagged', 'value', ['products']);

    expect($cache->get('tagged'))->toBe('value');
    $l2->rotateTagGenerations(['products']);
    expect($cache->get('tagged'))->toBeNull();
});

test('tiered cache rejects unsupported driver descriptors', function () {
    expect(fn() => Cache::tiered([['driver' => 'unknown-tier']]))
        ->toThrow(CacheInvalidArgumentException::class);
});


test('skipped L1 write-through invalidates promoted values before later reads', function () {
    $l1 = new ArrayCacheAdapter('skip-stale');
    $l2 = new ArrayCacheAdapter('skip-stale');
    $cache = Cache::tiered([$l1, $l2], writeToL1: false);

    expect($cache->set('single', 'old'))->toBeTrue()
        ->and($cache->get('single'))->toBe('old')
        ->and($l1->getItem('single')->get())->toBe('old')
        ->and($cache->set('single', 'new'))->toBeTrue()
        ->and($l1->getItem('single')->isHit())->toBeFalse()
        ->and($cache->get('single'))->toBe('new');

    expect($cache->setMultiple(['one' => 'old-1', 'two' => 'old-2']))->toBeTrue()
        ->and($cache->getMultiple(['one', 'two']))->toBe(['one' => 'old-1', 'two' => 'old-2'])
        ->and($cache->setMultiple(['one' => 'new-1', 'two' => 'new-2']))->toBeTrue()
        ->and($l1->getItem('one')->isHit())->toBeFalse()
        ->and($l1->getItem('two')->isHit())->toBeFalse()
        ->and($cache->getMultiple(['one', 'two']))->toBe(['one' => 'new-1', 'two' => 'new-2']);
});

test('tiered bulk reads preserve numeric-string logical keys', function () {
    $l1 = new ArrayCacheAdapter('numeric-tier');
    $l2 = new ArrayCacheAdapter('numeric-tier');
    $cache = Cache::tiered([$l1, $l2], writeToL1: false);

    foreach (['0', '123', '-1', '01'] as $key) {
        expect($cache->set($key, 'value-' . $key))->toBeTrue();
    }

    expect($cache->getMultiple(['0', '123', '-1', '01']))->toBe([
        0 => 'value-0',
        123 => 'value-123',
        -1 => 'value--1',
        '01' => 'value-01',
    ]);
});
