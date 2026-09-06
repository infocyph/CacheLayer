<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\CacheLayer\Exceptions\CacheInvalidArgumentException;

test('namespace is configured separately from the logical cache key', function () {
    $mytm = Cache::memory('mytm');
    $other = Cache::memory('other');

    $mytm->set('user', 'mytm-user');
    $other->set('user', 'other-user');

    expect($mytm->get('user'))->toBe('mytm-user')
        ->and($other->get('user'))->toBe('other-user');
});

test('colon remains reserved in public cache keys', function () {
    $cache = Cache::memory('mytm');

    expect(fn() => $cache->set('mytm:user', 'invalid'))
        ->toThrow(CacheInvalidArgumentException::class);
});
