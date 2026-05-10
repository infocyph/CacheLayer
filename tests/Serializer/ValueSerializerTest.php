<?php

// tests/Serializer/ValueSerializerTest.php

use Infocyph\CacheLayer\Serializer\ValueSerializer;

beforeEach(function () {
    ValueSerializer::clearResourceHandlers();
    ValueSerializer::useCompatibilitySecurity();
});

it('serialises and unserialises scalars and arrays', function () {
    $values = [
        123,
        'abc',
        [1, 2, 3],
        ['a' => 'x', 'b' => ['nested' => true]],
    ];

    foreach ($values as $v) {
        $blob = ValueSerializer::serialize($v);
        $out = ValueSerializer::unserialize($blob);
        expect($out)->toBe($v);
    }
});

it('round-trips closures', function () {
    $fn = fn (int $x): int => $x + 2;
    $blob = ValueSerializer::serialize($fn);
    $rest = ValueSerializer::unserialize($blob);

    expect(is_callable($rest))
        ->toBeTrue()
        ->and($rest(5))->toBe(7);
});

it('wraps and unwraps without full serialization', function () {
    $data = ['foo' => 'bar', 'baz' => [1, 2, 3]];
    $wrapped = ValueSerializer::wrap($data);
    expect($wrapped)->toBe($data);

    $unwrapped = ValueSerializer::unwrap($wrapped);
    expect($unwrapped)->toBe($data);
});

it('throws when wrapping a resource with no handler', function () {
    $s = fopen('php://memory', 'r+');

    expect(fn () => ValueSerializer::wrap($s))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => ValueSerializer::serialize($s))
        ->toThrow(InvalidArgumentException::class);

    fclose($s);
});

it('throws when registering the same resource handler twice', function () {
    ValueSerializer::registerResourceHandler('stream', fn ($r) => $r, fn ($d) => $d);

    expect(fn () => ValueSerializer::registerResourceHandler('stream', fn ($r) => $r, fn ($d) => $d))
        ->toThrow(InvalidArgumentException::class);
});

it('keeps serialized closure memo cache bounded', function () {
    for ($i = 0; $i < 2200; $i++) {
        ValueSerializer::isSerializedClosure('x'.$i);
    }

    $ref = new ReflectionClass(ValueSerializer::class);
    $memo = $ref->getProperty('serializedClosureMemo');

    expect(count($memo->getValue()))->toBeLessThanOrEqual(2048);
});

it('strict security mode blocks closure payloads', function () {
    ValueSerializer::useStrictSecurity();

    expect(fn () => ValueSerializer::serialize(fn () => 1))
        ->toThrow(InvalidArgumentException::class);

    ValueSerializer::useCompatibilitySecurity();
});
