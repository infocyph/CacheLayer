<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cache\Adapter\CachePayloadCodec;
use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\CacheLayer\Cache\CacheOptions;

test('payload codec signs and verifies CacheLayer v2 records', function () {
    $codec = new CachePayloadCodec(new CacheOptions(integrityKey: 'secret-key-123'));

    $blob = $codec->encode(['k' => 'v'], null, ['group' => 2]);
    expect(str_starts_with($blob, 'cl2-sig:'))->toBeTrue();

    $record = $codec->decode($blob);
    expect($record?->value)->toBe(['k' => 'v'])
        ->and($record?->tags)->toBe(['group' => 2]);
});

test('payload codec rejects tampered signed payload', function () {
    $codec = new CachePayloadCodec(new CacheOptions(integrityKey: 'secret-key-123'));
    $blob = $codec->encode('value', null);

    expect($codec->decode($blob . 'x'))->toBeNull();
});

test('payload codec policies are isolated between instances', function () {
    $unsigned = new CachePayloadCodec();
    $signed = new CachePayloadCodec(new CacheOptions(integrityKey: 'secret-key-123'));
    $blob = $unsigned->encode('value', null);

    expect($unsigned->decode($blob)?->value)->toBe('value')
        ->and($signed->decode($blob))->toBeNull()
        ->and($unsigned->decode($blob)?->value)->toBe('value');
});

test('long-running cache instances do not leak serialization policy', function () {
    $strict = Cache::memory('strict-worker', new CacheOptions(allowObjects: false));
    $permissive = Cache::memory('permissive-worker', new CacheOptions(allowObjects: true));

    expect($strict->set('object', new stdClass()))->toBeFalse()
        ->and($permissive->set('object', new stdClass()))->toBeTrue()
        ->and($permissive->get('object'))->toBeInstanceOf(stdClass::class)
        ->and($strict->set('scalar', 'still-valid'))->toBeTrue()
        ->and($strict->get('scalar'))->toBe('still-valid');
});

test('payload codec delegates only top-level closures to special serialization', function () {
    $codec = new CachePayloadCodec();
    $blob = $codec->encode(static fn(int $value): int => $value + 1, null);
    $closure = $codec->decode($blob)?->value;
    $resource = fopen('php://memory', 'r+');

    expect($closure)->toBeInstanceOf(Closure::class)
        ->and($closure(4))->toBe(5)
        ->and(fn() => $codec->encode(['nested' => static fn(): int => 1], null))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn() => $codec->encode($resource, null))
        ->toThrow(InvalidArgumentException::class);

    fclose($resource);
});

test('payload codec can disable closure serialization per cache instance', function () {
    $codec = new CachePayloadCodec(new CacheOptions(allowClosures: false));

    expect(fn() => $codec->encode(static fn(): int => 1, null))
        ->toThrow(InvalidArgumentException::class);
});

test('payload codec bounds decompressed payload size', function () {
    $writer = new CachePayloadCodec(new CacheOptions(compressionThreshold: 1, compressionLevel: 9));
    $compressed = $writer->encode(str_repeat('A', 8_192), null);
    $reader = new CachePayloadCodec(new CacheOptions(maxPayloadBytes: 512, compressionThreshold: 1));

    expect(str_starts_with($compressed, 'cl2-gz:'))->toBeTrue()
        ->and($reader->decode($compressed))->toBeNull();
});

test('payload codec does not decode legacy payload markers', function () {
    $codec = new CachePayloadCodec();

    expect($codec->decode('imx-gz:payload'))->toBeNull()
        ->and($codec->decode('imx-sig-v1:payload'))->toBeNull();
});
