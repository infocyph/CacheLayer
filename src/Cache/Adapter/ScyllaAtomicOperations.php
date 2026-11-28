<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cache\Adapter;

use Infocyph\CacheLayer\Cache\CacheRecord;
use Infocyph\CacheLayer\Cache\Item\CacheItem;
use Infocyph\CacheLayer\Support\OptionalCassandra;
use Psr\Cache\CacheItemInterface;
use RuntimeException;

/** @internal Native lightweight transactions for an explicitly enabled namespace. */
trait ScyllaAtomicOperations
{
    /** @throws \Throwable */
    public function atomicCompareAndSet(string $key, mixed $expected, CacheItemInterface $replacement): bool
    {
        if (!$this->atomicMode || !$this->supportsItem($replacement) || $replacement->getKey() !== $key) {
            return false;
        }
        $expiry = CachePayloadCodec::expirationFromItem($replacement);
        if ($expiry['ttl'] !== null && $expiry['ttl'] <= 0) {
            return false;
        }
        $row = $this->atomicRow($key);
        $record = $row === null ? null : $this->decodeRecordFromBlob($row, $key);
        if (!$record instanceof CacheRecord || $record->tags !== [] || $record->value !== $expected || $row === null) {
            return false;
        }

        return $this->atomicReplace($replacement, $expiry, $row);
    }

    /** @throws \Throwable */
    public function atomicGetAndDelete(string $key): CacheItemInterface
    {
        $this->discardDeferredKey($key);
        if (!$this->atomicMode) {
            return $this->genericMiss($key);
        }
        for ($attempt = 0; $attempt < 3; ++$attempt) {
            $row = $this->atomicRow($key);
            if ($row === null) {
                return $this->genericMiss($key);
            }
            $record = $this->decodeRecordFromBlob($row, $key);
            if ($this->atomicApplied(
                "DELETE FROM {$this->qualifiedTable} WHERE ns = ? AND bucket = ? AND ckey = ? IF payload = ?",
                [...$this->atomicIdentity($key), OptionalCassandra::blob($row)],
            )) {
                return $record instanceof CacheRecord && $record->tags === []
                    ? $this->genericItemFromRecord($key, $record) : $this->genericMiss($key);
            }
        }

        throw new RuntimeException('ScyllaDB atomic consume contention exhausted.');
    }

    /** @throws \Throwable */
    public function atomicSetIfAbsent(CacheItemInterface $item): bool
    {
        if (!$this->atomicMode || !$this->supportsItem($item)) {
            return false;
        }
        $expiry = CachePayloadCodec::expirationFromItem($item);
        if ($expiry['ttl'] !== null && $expiry['ttl'] <= 0) {
            return false;
        }
        for ($attempt = 0; $attempt < 3; ++$attempt) {
            if ($this->atomicInsert($item, $expiry)) {
                return true;
            }
            $row = $this->atomicRow($item->getKey());
            if ($row === null) {
                continue;
            }
            if ($this->decodeRecordFromBlob($row, $item->getKey()) instanceof CacheRecord) {
                return false;
            }
            if ($this->atomicReplace($item, $expiry, $row)) {
                return true;
            }
        }

        throw new RuntimeException('ScyllaDB atomic insert contention exhausted.');
    }

    public function supportsAtomicCache(): bool
    {
        return $this->atomicMode;
    }

    /**
     * @param array<int, mixed> $arguments
     * @throws RuntimeException
     */
    private function atomicApplied(string $cql, array $arguments): bool
    {
        $row = $this->firstRow($cql, $arguments);
        if (!is_bool($row['[applied]'] ?? null)) {
            throw new RuntimeException('ScyllaDB LWT returned no definitive applied result.');
        }

        return $row['[applied]'];
    }

    /** @return list<int|string> */
    private function atomicIdentity(string $key): array
    {
        return [$this->ns, $this->bucket($key), $this->mapData($key)];
    }

    /**
     * @param array{ttl:int|null,expiresAt:int|null} $expiry
     * @throws \Throwable
     */
    private function atomicInsert(CacheItemInterface $item, array $expiry): bool
    {
        $this->assertAtomicUntagged($item);

        return $this->atomicApplied(
            "INSERT INTO {$this->qualifiedTable} (ns, bucket, ckey, payload, expires) VALUES (?, ?, ?, ?, ?) IF NOT EXISTS USING TTL ?",
            [...$this->atomicIdentity($item->getKey()), OptionalCassandra::blob($this->encodeItem($item, $expiry['expiresAt'])), OptionalCassandra::bigint($expiry['expiresAt']), $expiry['ttl'] ?? 0],
        );
    }

    /**
     * @param array{ttl:int|null,expiresAt:int|null} $expiry
     * @throws \Throwable
     */
    private function atomicReplace(CacheItemInterface $item, array $expiry, string $observed): bool
    {
        $this->assertAtomicUntagged($item);

        return $this->atomicApplied(
            "UPDATE {$this->qualifiedTable} USING TTL ? SET payload = ?, expires = ? WHERE ns = ? AND bucket = ? AND ckey = ? IF payload = ?",
            [$expiry['ttl'] ?? 0, OptionalCassandra::blob($this->encodeItem($item, $expiry['expiresAt'])), OptionalCassandra::bigint($expiry['expiresAt']), ...$this->atomicIdentity($item->getKey()), OptionalCassandra::blob($observed)],
        );
    }

    /** @throws RuntimeException */
    private function atomicRow(string $key): ?string
    {
        $row = $this->firstRow(
            "SELECT payload, expires FROM {$this->qualifiedTable} WHERE ns = ? AND bucket = ? AND ckey = ? LIMIT 1",
            $this->atomicIdentity($key),
        );
        if ($row === null) {
            return null;
        }
        $payload = ScyllaValueNormalizer::string($row['payload'] ?? null);
        if ($payload === null) {
            throw new RuntimeException('ScyllaDB atomic read returned an invalid payload.');
        }

        return $payload;
    }

    /** @throws RuntimeException */
    private function assertAtomicUntagged(CacheItemInterface $item): void
    {
        if ($item instanceof CacheItem && $item->getTagGenerations() !== []) {
            throw new RuntimeException('ScyllaDB atomic namespaces require untagged entries.');
        }
    }

    /** @throws \Throwable */
    private function clearAtomicRows(): bool
    {
        for ($bucket = 0; $bucket < $this->bucketCount; ++$bucket) {
            foreach ($this->queryRows("SELECT ckey FROM {$this->qualifiedTable} WHERE ns = ? AND bucket = ?", [$this->ns, $bucket]) as $row) {
                $key = ScyllaValueNormalizer::string($row['ckey'] ?? null);
                if ($key === null) {
                    throw new RuntimeException('ScyllaDB atomic clear returned an invalid key.');
                }
                $this->atomicApplied("DELETE FROM {$this->qualifiedTable} WHERE ns = ? AND bucket = ? AND ckey = ? IF EXISTS", [$this->ns, $bucket, $key]);
            }
        }
        $this->deferred = [];

        return true;
    }

    /** @throws \Throwable */
    private function saveAtomic(CacheItemInterface $item): bool
    {
        if (!$this->supportsItem($item)) {
            return false;
        }
        $expiry = CachePayloadCodec::expirationFromItem($item);
        if ($expiry['ttl'] !== null && $expiry['ttl'] <= 0) {
            return $this->deleteItem($item->getKey());
        }
        for ($attempt = 0; $attempt < 3; ++$attempt) {
            if ($this->atomicInsert($item, $expiry)) {
                return true;
            }
            $row = $this->atomicRow($item->getKey());
            if ($row !== null && $this->atomicReplace($item, $expiry, $row)) {
                return true;
            }
        }

        throw new RuntimeException('ScyllaDB atomic save contention exhausted.');
    }
}
