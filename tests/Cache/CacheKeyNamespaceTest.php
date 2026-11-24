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

test('TTL overflow is rejected and long remember jitter remains integer-safe', function (): void {
    $cache = Cache::memory('large-ttl');
    expect(fn (): bool => $cache->set('overflow', 'value', PHP_INT_MAX))
        ->toThrow(CacheInvalidArgumentException::class)
        ->and(fn (): \Psr\Cache\CacheItemInterface => $cache->getItem('overflow')->expiresAfter(PHP_INT_MAX))
        ->toThrow(CacheInvalidArgumentException::class);

    expect($cache->remember('long', static fn (): string => 'long-value', intdiv(PHP_INT_MAX, 2)))->toBe('long-value')
        ->and($cache->get('long'))->toBe('long-value');
});

test('absolute expiry remains unchanged through bulk storage and tier promotion', function (): void {
    $l1 = new \Infocyph\CacheLayer\Cache\Adapter\ArrayCacheAdapter('expiry-l1');
    $l2 = new \Infocyph\CacheLayer\Cache\Adapter\ArrayCacheAdapter('expiry-l2');
    $cache = Cache::tiered([$l1, $l2], writeToL1: false);
    $expires = time() + 600;
    $item = $cache->getItem('key')->set('value')->expiresAt(new DateTimeImmutable()->setTimestamp($expires));
    expect($cache->saveDeferred($item))->toBeTrue()
        ->and($cache->commit())->toBeTrue();
    $read = $cache->getItem('key');
    expect($read->get())->toBe('value')
        ->and(\Infocyph\CacheLayer\Cache\Adapter\CachePayloadCodec::expirationFromItem($read)['expiresAt'])->toBe($expires)
        ->and(\Infocyph\CacheLayer\Cache\Adapter\CachePayloadCodec::expirationFromItem($l1->getItem('key'))['expiresAt'])->toBe($expires)
        ->and(\Infocyph\CacheLayer\Cache\Adapter\CachePayloadCodec::expirationFromItem($l2->getItem('key'))['expiresAt'])->toBe($expires);
});

test('an extreme past absolute expiry is a miss rather than an integer overflow', function (): void {
    $cache = Cache::memory('past-ttl');
    $past = new DateTimeImmutable()->setTimestamp(PHP_INT_MIN);
    $item = $cache->getItem('key')->set('value')->expiresAt($past);
    expect($cache->save($item))->toBeTrue()
        ->and($cache->has('key'))->toBeFalse()
        ->and($cache->set('key', 'value', $past))->toBeTrue()
        ->and($cache->has('key'))->toBeFalse();
});
