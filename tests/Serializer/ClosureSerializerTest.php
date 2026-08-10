<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Serializer\ClosureSerializer;

it('serializes and unserializes closures', function () {
    $closure = static fn(int $value): int => $value + 2;
    $payload = ClosureSerializer::serialize($closure);
    $restored = ClosureSerializer::unserialize($payload);

    expect(ClosureSerializer::isSerialized($payload))->toBeTrue()
        ->and($restored(5))->toBe(7);
});

it('rejects malformed and non-closure payloads', function () {
    expect(ClosureSerializer::isSerialized('not-a-closure'))->toBeFalse()
        ->and(ClosureSerializer::isSerialized('cls1:'))->toBeFalse()
        ->and(fn() => ClosureSerializer::unserialize('not-a-closure'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn() => ClosureSerializer::unserialize('cls1:' . base64_encode(serialize(42))))
        ->toThrow(InvalidArgumentException::class);
});

it('signs and verifies closure payloads', function () {
    $serializer = ClosureSerializer::signed('closure-test-key');
    $payload = $serializer->serialize(static fn(int $value): int => $value * 3);
    $restored = $serializer->unserialize($payload);

    expect($restored(4))->toBe(12)
        ->and(fn() => ClosureSerializer::signed('wrong-key')->unserialize($payload))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn() => $serializer->unserialize($payload . 'tampered'))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects an empty signing key', function () {
    expect(fn() => ClosureSerializer::signed(''))->toThrow(InvalidArgumentException::class);
});
