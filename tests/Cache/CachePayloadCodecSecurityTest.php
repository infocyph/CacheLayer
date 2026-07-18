<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cache\Adapter\CachePayloadCodec;

beforeEach(function () {
    CachePayloadCodec::configureSecurity(null, 8_388_608);
});

afterEach(function () {
    CachePayloadCodec::configureSecurity(null, 8_388_608);
});

test('payload codec signs and verifies payload integrity when key is configured', function () {
    CachePayloadCodec::configureSecurity('secret-key-123', 8_388_608);

    $blob = CachePayloadCodec::encode(['k' => 'v'], null);
    expect(str_starts_with($blob, 'imx-sig-v1:'))->toBeTrue();

    $decoded = CachePayloadCodec::decode($blob);
    expect($decoded)->toBeArray()
        ->and($decoded['value'])->toBe(['k' => 'v']);
});

test('payload codec rejects tampered signed payload', function () {
    CachePayloadCodec::configureSecurity('secret-key-123', 8_388_608);

    $blob = CachePayloadCodec::encode('value', null);
    $tampered = $blob.'x';

    expect(CachePayloadCodec::decode($tampered))->toBeNull();
});

test('payload codec rejects unsigned payload when integrity key is configured', function () {
    $unsigned = CachePayloadCodec::encode('legacy', null);

    CachePayloadCodec::configureSecurity('secret-key-123', 8_388_608);
    expect(CachePayloadCodec::decode($unsigned))->toBeNull();
});
