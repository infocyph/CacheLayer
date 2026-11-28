<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cache\Adapter;

use Infocyph\CacheLayer\Cache\Item\CacheItem;
use Infocyph\CacheLayer\Cache\Lock\FileLockProvider;
use Psr\Cache\CacheItemInterface;
use RuntimeException;

/** @internal APCu arbitrary-value coordination for explicitly enabled namespaces. */
trait ApcuAtomicOperations
{
    private ?FileLockProvider $atomicLockProvider = null;

    /** @throws \Throwable */
    public function atomicCompareAndSet(string $key, mixed $expected, CacheItemInterface $replacement): bool
    {
        if (!$this->atomicMode || !$this->supportsItem($replacement) || $replacement->getKey() !== $key) {
            return false;
        }
        $expiration = CachePayloadCodec::expirationFromItem($replacement);
        if ($expiration['ttl'] !== null && $expiration['ttl'] <= 0) {
            return false;
        }

        return $this->withAtomicLock(function () use ($key, $expected, $replacement): bool {
            $current = $this->getItem($key);

            return $current->isHit() && $current->get() === $expected
                && $this->atomicTagsCurrent($current) && $this->saveUnlocked($replacement);
        });
    }

    /** @throws \Throwable */
    public function atomicGetAndDelete(string $key): CacheItemInterface
    {
        $this->discardDeferredKey($key);
        if (!$this->atomicMode) {
            return $this->genericMiss($key);
        }

        return $this->withAtomicLock(function () use ($key): CacheItemInterface {
            $current = $this->getItem($key);
            if (!$this->deleteItemUnlocked($key)) {
                throw new RuntimeException('APCu atomic consume failed.');
            }

            return $current->isHit() && $this->atomicTagsCurrent($current) ? $current : $this->genericMiss($key);
        });
    }

    /** @throws \Throwable */
    public function atomicSetIfAbsent(CacheItemInterface $item): bool
    {
        if (!$this->atomicMode || !$this->supportsItem($item)) {
            return false;
        }
        $expiration = CachePayloadCodec::expirationFromItem($item);
        if ($expiration['ttl'] !== null && $expiration['ttl'] <= 0) {
            return false;
        }

        return $this->withAtomicLock(function () use ($item): bool {
            $current = $this->getItem($item->getKey());
            if ($current->isHit() && $this->atomicTagsCurrent($current)) {
                return false;
            }

            return $this->saveUnlocked($item);
        });
    }

    public function supportsAtomicCache(): bool
    {
        return $this->atomicMode;
    }

    private function atomicTagsCurrent(CacheItem $item): bool
    {
        $tags = $item->getTagGenerations();
        if ($tags === []) {
            return true;
        }
        $actual = $this->readTagGenerations(array_map(static fn(int|string $tag): string => (string) $tag, array_keys($tags)));

        return array_all($tags, static fn(string $generation, int|string $tag): bool => ($actual[$tag] ?? null) === $generation);
    }

    /**
     * @template T
     * @param callable(): T $operation
     * @return T
     * @throws RuntimeException
     * @throws \InvalidArgumentException
     */
    private function withAtomicLock(callable $operation): mixed
    {
        $provider = $this->atomicLockProvider ??= new FileLockProvider();
        $handle = $provider->acquire('apcu-atomic:' . $this->ns, 5.0);
        if ($handle === null) {
            throw new RuntimeException('Unable to acquire APCu atomic namespace lock.');
        }

        try {
            return $operation();
        } finally {
            $provider->release($handle);
        }
    }
}
