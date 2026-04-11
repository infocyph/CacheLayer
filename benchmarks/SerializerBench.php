<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Benchmarks;

use Infocyph\CacheLayer\Serializer\ValueSerializer;
use PhpBench\Attributes as Bench;

#[Bench\Iterations(5)]
#[Bench\Revs(1000)]
final class SerializerBench
{
    /**
     * @var array<string, mixed>
     */
    private array $payload;

    public function __construct()
    {
        $this->payload = [
            'id' => 123,
            'name' => 'cache-layer',
            'flags' => [true, false, true],
            'meta' => ['release' => 2, 'enabled' => true],
        ];
    }

    public function benchEncodeDecodeArray(): int
    {
        $blob = ValueSerializer::encode($this->payload);
        $decoded = ValueSerializer::decode($blob);

        return (int) ($decoded['id'] ?? 0);
    }

    public function benchSerializeUnserializeArray(): int
    {
        $blob = ValueSerializer::serialize($this->payload);
        $decoded = ValueSerializer::unserialize($blob);

        return (int) ($decoded['id'] ?? 0);
    }

    public function benchSerializeUnserializeClosure(): int
    {
        $fn = static fn(int $v): int => $v + 5;
        $blob = ValueSerializer::serialize($fn);
        $restored = ValueSerializer::unserialize($blob);

        return $restored(10);
    }
}
