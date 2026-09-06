<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cache;

use DateInterval;
use DateTimeInterface;
use Infocyph\CacheLayer\Cache\Adapter\AtomicCachePoolInterface;
use Infocyph\CacheLayer\Cache\Adapter\InternalCachePoolInterface;
use Infocyph\CacheLayer\Cache\Metrics\CacheMetricsCollectorInterface;
use Infocyph\CacheLayer\Exceptions\CacheBackendException;
use Psr\Cache\CacheItemInterface;
use Throwable;

/** @internal Exposed through AtomicCacheProviderInterface::atomic(). */
final class AtomicCache implements AtomicCacheInterface
{
    public function __construct(
        private readonly AtomicCachePoolInterface $adapter,
        private readonly CacheOptions $options,
        private CacheMetricsCollectorInterface $metrics,
    ) {}

    public static function fromAdapter(
        InternalCachePoolInterface $adapter,
        CacheOptions $options,
        CacheMetricsCollectorInterface $metrics,
    ): ?self {
        if (!$adapter instanceof AtomicCachePoolInterface) {
            return null;
        }

        return new self($adapter, $options, $metrics);
    }

    public function compareAndSet(
        string $key,
        mixed $expected,
        mixed $replacement,
        null|int|DateInterval|DateTimeInterface $ttl = null,
    ): bool {
        CacheInput::key($key);
        $ttlSeconds = CacheInput::ttl($ttl);
        $this->metric('atomic_compare_and_set');
        if ($ttlSeconds !== null && $ttlSeconds <= 0) {
            $this->metric('atomic_compare_and_set_miss');

            return false;
        }

        $item = $this->adapter->createItem($key)->set($replacement)->expiresAfter($ttlSeconds);
        $stored = $this->backend(
            fn(): bool => $this->adapter->atomicCompareAndSet($key, $expected, $item),
            false,
        );
        $this->metric($stored ? 'atomic_compare_and_set_success' : 'atomic_compare_and_set_miss');

        return $stored;
    }

    public function getAndDelete(string $key, mixed $default = null): mixed
    {
        CacheInput::key($key);
        $item = $this->backend(
            fn(): CacheItemInterface => $this->adapter->atomicGetAndDelete($key),
            $this->adapter->createItem($key),
        );
        $this->metric('atomic_get_and_delete');
        if (!$item->isHit()) {
            $this->metric('atomic_get_and_delete_miss');

            return $default;
        }
        $this->metric('atomic_get_and_delete_hit');

        return $item->get();
    }

    public function setIfAbsent(
        string $key,
        mixed $value,
        null|int|DateInterval|DateTimeInterface $ttl = null,
    ): bool {
        CacheInput::key($key);
        $ttlSeconds = CacheInput::ttl($ttl);
        $this->metric('atomic_set_if_absent');
        if ($ttlSeconds !== null && $ttlSeconds <= 0) {
            $this->metric('atomic_set_if_absent_miss');

            return false;
        }

        $item = $this->adapter->createItem($key)->set($value)->expiresAfter($ttlSeconds);
        $stored = $this->backend(
            fn(): bool => $this->adapter->atomicSetIfAbsent($item),
            false,
        );
        $this->metric($stored ? 'atomic_set_if_absent_success' : 'atomic_set_if_absent_miss');

        return $stored;
    }

    /** @internal */
    public function setMetricsCollector(CacheMetricsCollectorInterface $metrics): void
    {
        $this->metrics = $metrics;
    }

    /**
     * @template T
     * @param callable(): T $operation
     * @param T $fallback
     * @return T
     */
    private function backend(callable $operation, mixed $fallback): mixed
    {
        try {
            return $operation();
        } catch (Throwable $failure) {
            $this->metric('backend_failure');
            if (!$this->options->failOpen) {
                throw $failure instanceof CacheBackendException
                    ? $failure
                    : new CacheBackendException('Cache backend operation failed.', 0, $failure);
            }

            return $fallback;
        }
    }

    private function metric(string $name): void
    {
        $this->metrics->increment($this->adapter::class, $name);
    }
}
