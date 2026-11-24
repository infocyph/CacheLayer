<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Benchmarks;

use Infocyph\CacheLayer\Serializer\ClosureSerializer;
use PhpBench\Attributes as Bench;

/** @api PHPBench discovers this benchmark entry point through attributes. */
#[Bench\Iterations(5)]
#[Bench\Revs(1000)]
final class ClosureSerializerBench
{
    /**
     * @throws \InvalidArgumentException
     * @throws \UnexpectedValueException
     */
    public function benchSerializeUnserializeClosure(): int
    {
        $closure = static fn(int $value): int => $value + 5;
        $payload = ClosureSerializer::serialize($closure);
        $restored = ClosureSerializer::unserialize($payload);

        $value = $restored(10);
        if (!is_int($value)) {
            throw new \UnexpectedValueException('The benchmark expected an integer closure result.');
        }

        return $value;
    }

    /**
     * @throws \InvalidArgumentException
     * @throws \UnexpectedValueException
     */
    public function benchSignedClosure(): int
    {
        $serializer = ClosureSerializer::signed('benchmark-signing-key');
        $payload = $serializer->serialize(static fn(int $value): int => $value + 5);
        $restored = $serializer->unserialize($payload);

        $value = $restored(10);
        if (!is_int($value)) {
            throw new \UnexpectedValueException('The benchmark expected an integer closure result.');
        }

        return $value;
    }
}
