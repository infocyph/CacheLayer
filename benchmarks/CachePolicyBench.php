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

/** @api PHPBench discovers this benchmark entry point through attributes. */
#[Bench\Iterations(5)]
#[Bench\Revs(500)]
final class CachePolicyBench
{
    private string $payload;

    public function __construct()
    {
        $this->payload = str_repeat('cachelayer-payload-', 256);
    }

    /**
     * @param array{threshold:int} $params
     * @throws \Infocyph\CacheLayer\Exceptions\CacheInvalidArgumentException
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     * @throws \UnexpectedValueException
     */
    #[Bench\ParamProviders('provideCompressionThresholds')]
    public function benchCompressedCodec(array $params): int
    {
        $codec = new CachePayloadCodec(new CacheOptions(compressionThreshold: $params['threshold']));
        $record = $codec->decode($codec->encode($this->payload, null));

        $value = $record?->value;
        if (!is_string($value)) {
            throw new \UnexpectedValueException('The benchmark expected a decoded string.');
        }

        return strlen($value);
    }

    /**
     * @throws \Infocyph\CacheLayer\Exceptions\CacheInvalidArgumentException
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     * @throws \UnexpectedValueException
     */
    public function benchHmacCodec(): int
    {
        $codec = new CachePayloadCodec(new CacheOptions(integrityKey: 'benchmark-secret'));
        $payload = $codec->encode(
            $this->payload,
            null,
            storageIdentity: 'benchmark',
            key: 'payload',
        );
        $record = $codec->decode($payload, 'benchmark', 'payload');

        $value = $record?->value;
        if (!is_string($value)) {
            throw new \UnexpectedValueException('The benchmark expected a decoded string.');
        }

        return strlen($value);
    }

    /**
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws \Psr\Cache\InvalidArgumentException
     * @throws \UnexpectedValueException
     */
    public function benchPlainGet(): int
    {
        $cache = Cache::memory('plain-get');
        $cache->set('hot', 42);

        $value = $cache->get('hot');
        if (!is_int($value)) {
            throw new \UnexpectedValueException('The benchmark expected an integer cache hit.');
        }

        return $value;
    }

    /**
     * @throws \Psr\SimpleCache\InvalidArgumentException
     */
    public function benchPlainSet(): int
    {
        return Cache::memory('plain-set')->set('key', 42) ? 1 : 0;
    }

    /**
     * @throws \Infocyph\CacheLayer\Exceptions\CacheInvalidArgumentException
     * @throws \Psr\Cache\InvalidArgumentException
     * @throws \UnexpectedValueException
     */
    public function benchRememberContendedTimeout(): int
    {
        $lock = new class implements LockProviderInterface {
            #[\Override]
            public function acquire(string $key, float $waitSeconds, float $leaseSeconds = 30.0): ?LockHandle
            {
                unset($key, $waitSeconds, $leaseSeconds);

                return null;
            }

            #[\Override]
            public function refresh(?LockHandle $handle, float $leaseSeconds): bool
            {
                unset($handle, $leaseSeconds);

                return false;
            }

            #[\Override]
            public function release(?LockHandle $handle): void
            {
                unset($handle);
            }
        };
        $cache = new Cache(new ArrayCacheAdapter('contended'), $lock);

        $value = $cache->remember('cold', static fn(): int => 42, 60);
        if (!is_int($value)) {
            throw new \UnexpectedValueException('The benchmark expected an integer cache hit.');
        }

        return $value;
    }

    /**
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws \Infocyph\CacheLayer\Exceptions\CacheInvalidArgumentException
     * @throws \Psr\Cache\InvalidArgumentException
     * @throws \UnexpectedValueException
     */
    public function benchRememberHit(): int
    {
        $cache = Cache::memory('remember-hit');
        $cache->set('hot', 42);

        $value = $cache->remember('hot', static fn(): int => 0, 60);
        if (!is_int($value)) {
            throw new \UnexpectedValueException('The benchmark expected an integer cache hit.');
        }

        return $value;
    }

    /**
     * @throws \Infocyph\CacheLayer\Exceptions\CacheInvalidArgumentException
     * @throws \Psr\Cache\InvalidArgumentException
     * @throws \UnexpectedValueException
     */
    public function benchRememberMiss(): int
    {
        $cache = Cache::memory('remember-miss');

        $value = $cache->remember('cold', static fn(): int => 42, 60);
        if (!is_int($value)) {
            throw new \UnexpectedValueException('The benchmark expected an integer cache hit.');
        }

        return $value;
    }

    /**
     * @throws \Infocyph\CacheLayer\Exceptions\CacheInvalidArgumentException
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws \Psr\Cache\InvalidArgumentException
     * @throws \UnexpectedValueException
     */
    public function benchTaggedGet(): int
    {
        $cache = Cache::memory('tagged-get');
        $cache->setTagged('hot', 42, ['group']);

        $value = $cache->get('hot');
        if (!is_int($value)) {
            throw new \UnexpectedValueException('The benchmark expected an integer cache hit.');
        }

        return $value;
    }

    /**
     * @throws \Infocyph\CacheLayer\Exceptions\CacheInvalidArgumentException
     * @throws \Psr\SimpleCache\InvalidArgumentException
     */
    public function benchTaggedSet(): int
    {
        return Cache::memory('tagged-set')->setTagged('key', 42, ['group']) ? 1 : 0;
    }

    /**
     * @throws \Infocyph\CacheLayer\Exceptions\CacheInvalidArgumentException
     * @throws \InvalidArgumentException
     * @throws \RuntimeException
     * @throws \UnexpectedValueException
     */
    public function benchUnsignedCodec(): int
    {
        $codec = new CachePayloadCodec();
        $record = $codec->decode($codec->encode($this->payload, null));

        $value = $record?->value;
        if (!is_string($value)) {
            throw new \UnexpectedValueException('The benchmark expected a decoded string.');
        }

        return strlen($value);
    }

    /** @return iterable<string, array{threshold:int}> */
    public function provideCompressionThresholds(): iterable
    {
        yield '512 bytes' => ['threshold' => 512];
        yield '4096 bytes' => ['threshold' => 4096];
        yield '8192 bytes' => ['threshold' => 8192];
    }
}
