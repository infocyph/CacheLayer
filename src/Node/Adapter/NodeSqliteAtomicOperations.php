<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Node\Adapter;

use Infocyph\CacheLayer\Cache\Adapter\CachePayloadCodec;
use Infocyph\CacheLayer\Cache\Item\CacheItem;
use Infocyph\CacheLayer\Node\Exception\NodeCacheStorageException;
use Psr\Cache\CacheItemInterface;
use Throwable;

/** @internal Native node SQLite read-modify-write operations. */
trait NodeSqliteAtomicOperations
{
    /**
     * @throws Throwable
     * @throws \Infocyph\CacheLayer\Exceptions\CacheInvalidArgumentException
     * @throws NodeCacheStorageException
     */
    public function atomicCompareAndSet(string $key, mixed $expected, CacheItemInterface $replacement): bool
    {
        if (!$this->supportsItem($replacement) || $replacement->getKey() !== $key) {
            return false;
        }
        $expiration = CachePayloadCodec::expirationFromItem($replacement);
        if ($expiration['ttl'] !== null && $expiration['ttl'] <= 0) {
            return false;
        }

        return $this->atomicTransaction(function () use ($key, $expected, $replacement): bool {
            $current = $this->getItem($key);
            if (!$current->isHit() || $current->get() !== $expected || !$this->atomicTagsCurrent($current)) {
                return false;
            }

            return $this->save($replacement);
        });
    }

    /**
     * @throws Throwable
     * @throws \Infocyph\CacheLayer\Exceptions\CacheInvalidArgumentException
     * @throws NodeCacheStorageException
     */
    public function atomicGetAndDelete(string $key): CacheItemInterface
    {
        $this->discardDeferredKey($key);

        return $this->atomicTransaction(function () use ($key): CacheItemInterface {
            $current = $this->getItem($key);
            if (!$this->deleteItem($key)) {
                throw new NodeCacheStorageException('Unable to consume node SQLite cache entry.');
            }

            return $current->isHit() && $this->atomicTagsCurrent($current) ? $current : $this->genericMiss($key);
        });
    }

    /**
     * @throws Throwable
     * @throws \Infocyph\CacheLayer\Exceptions\CacheInvalidArgumentException
     * @throws NodeCacheStorageException
     */
    public function atomicSetIfAbsent(CacheItemInterface $item): bool
    {
        if (!$this->supportsItem($item)) {
            return false;
        }
        $expiration = CachePayloadCodec::expirationFromItem($item);
        if ($expiration['ttl'] !== null && $expiration['ttl'] <= 0) {
            return false;
        }

        return $this->atomicTransaction(function () use ($item): bool {
            $current = $this->getItem($item->getKey());
            if ($current->isHit() && $this->atomicTagsCurrent($current)) {
                return false;
            }

            return $this->save($item);
        });
    }

    /**
     * @throws NodeCacheStorageException
     * @throws \Infocyph\CacheLayer\Exceptions\CacheInvalidArgumentException
     */
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
     * @throws Throwable
     */
    private function atomicTransaction(callable $operation): mixed
    {
        if ($this->ownsTransaction || $this->connection->inTransaction()) {
            throw new NodeCacheStorageException('Node atomic operations require an owned transaction.');
        }
        if ($this->connection->exec('BEGIN IMMEDIATE') === false) {
            throw new NodeCacheStorageException('Unable to begin node atomic transaction.');
        }
        $this->ownsTransaction = true;

        try {
            $result = $operation();
            if ($this->connection->exec('COMMIT') === false) {
                throw new NodeCacheStorageException('Unable to commit node atomic transaction.');
            }

            return $result;
        } catch (Throwable $failure) {
            try {
                $this->connection->exec('ROLLBACK');
            } catch (Throwable) {
                // Keep the original failure.
            }

            throw $failure;
        } finally {
            $this->ownsTransaction = false;
        }
    }
}
