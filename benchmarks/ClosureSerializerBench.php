<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Benchmarks;

use Infocyph\CacheLayer\Serializer\ClosureSerializer;
use PhpBench\Attributes as Bench;

#[Bench\Iterations(5)]
#[Bench\Revs(1000)]
final class ClosureSerializerBench
{
    public function benchSerializeUnserializeClosure(): int
    {
        $closure = static fn(int $value): int => $value + 5;
        $payload = ClosureSerializer::serialize($closure);
        $restored = ClosureSerializer::unserialize($payload);

        return $restored(10);
    }

    public function benchSignedClosure(): int
    {
        $serializer = ClosureSerializer::signed('benchmark-signing-key');
        $payload = $serializer->serialize(static fn(int $value): int => $value + 5);
        $restored = $serializer->unserialize($payload);

        return $restored(10);
    }
}
