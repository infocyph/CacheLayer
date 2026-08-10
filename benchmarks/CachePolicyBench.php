<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Benchmarks;

use Infocyph\CacheLayer\Cache\Adapter\ArrayCacheAdapter;
use Infocyph\CacheLayer\Cache\Adapter\CachePayloadCodec;
use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\CacheLayer\Cache\CacheOptions;
use Infocyph\CacheLayer\Cache\Lock\LockHandle;
use Infocyph\CacheLayer\Cache\Lock\LockProviderInterface;
use PhpBench\Attributes as Bench;

#[Bench\Iterations(5)]
#[Bench\Revs(500)]
final class CachePolicyBench
{
    private string $payload;

    public function __construct()
    {
        $this->payload = str_repeat('cachelayer-payload-', 256);
    }

    /** @param array{threshold:int} $params */
    #[Bench\ParamProviders('provideCompressionThresholds')]
    public function benchCompressedCodec(array $params): int
    {
        $codec = new CachePayloadCodec(new CacheOptions(compressionThreshold: $params['threshold']));
        $record = $codec->decode($codec->encode($this->payload, null));

        return strlen((string) $record?->value);
    }

    public function benchHmacCodec(): int
    {
        $codec = new CachePayloadCodec(new CacheOptions(integrityKey: 'benchmark-secret'));
        $record = $codec->decode($codec->encode($this->payload, null));

        return strlen((string) $record?->value);
    }

    public function benchPlainGet(): int
    {
        $cache = Cache::memory('plain-get');
        $cache->set('hot', 42);

        return (int) $cache->get('hot');
    }

    public function benchPlainSet(): int
    {
        return Cache::memory('plain-set')->set('key', 42) ? 1 : 0;
    }

    public function benchRememberContendedTimeout(): int
    {
        $lock = new class implements LockProviderInterface {
            public function acquire(string $key, float $waitSeconds, float $leaseSeconds = 30.0): ?LockHandle
            {
                unset($key, $waitSeconds, $leaseSeconds);

                return null;
            }

            public function refresh(?LockHandle $handle, float $leaseSeconds): bool
            {
                unset($handle, $leaseSeconds);

                return false;
            }

            public function release(?LockHandle $handle): void
            {
                unset($handle);
            }
        };
        $cache = new Cache(new ArrayCacheAdapter('contended'), $lock);

        return (int) $cache->remember('cold', static fn(): int => 42, 60);
    }

    public function benchRememberHit(): int
    {
        $cache = Cache::memory('remember-hit');
        $cache->set('hot', 42);

        return (int) $cache->remember('hot', static fn(): int => 0, 60);
    }

    public function benchRememberMiss(): int
    {
        $cache = Cache::memory('remember-miss');

        return (int) $cache->remember('cold', static fn(): int => 42, 60);
    }

    public function benchTaggedGet(): int
    {
        $cache = Cache::memory('tagged-get');
        $cache->setTagged('hot', 42, ['group']);

        return (int) $cache->get('hot');
    }

    public function benchTaggedSet(): int
    {
        return Cache::memory('tagged-set')->setTagged('key', 42, ['group']) ? 1 : 0;
    }

    public function benchUnsignedCodec(): int
    {
        $codec = new CachePayloadCodec();
        $record = $codec->decode($codec->encode($this->payload, null));

        return strlen((string) $record?->value);
    }

    public function provideCompressionThresholds(): iterable
    {
        yield '512 bytes' => ['threshold' => 512];
        yield '4096 bytes' => ['threshold' => 4096];
        yield '8192 bytes' => ['threshold' => 8192];
    }
}
