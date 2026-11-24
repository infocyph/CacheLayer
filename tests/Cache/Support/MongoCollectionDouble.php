<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Tests\Cache\Support;

use InvalidArgumentException;
use RuntimeException;

final class MongoCollectionDouble
{
    /** @var array<string, array<string, mixed>> */
    public array $docs = [];

    public int $bulkWrites = 0;

    public int $findCalls = 0;

    /**
     * @param array{_id?:string,ns?:string} $filter
     */
    public function countDocuments(array $filter): int
    {
        $id = $filter['_id'] ?? null;
        $now = time();
        $count = 0;

        foreach ($this->docs as $key => $doc) {
            if (is_string($id) && $key !== $id) {
                continue;
            }
            if (isset($filter['ns']) && ($doc['ns'] ?? null) !== $filter['ns']) {
                continue;
            }
            $expires = is_numeric($doc['expires'] ?? null) ? (int) $doc['expires'] : null;
            if ($expires !== null && $expires <= $now) {
                continue;
            }
            $count++;
        }

        return $count;
    }

    /**
     * @param array{_id?:array{'$in':array<array-key,string>},ns?:string} $filter
     * @throws InvalidArgumentException
     */
    public function deleteMany(array $filter): void
    {
        foreach ($this->docs as $key => $doc) {
            $ids = $filter['_id']['$in'] ?? null;
            if ($ids !== null && !array_is_list($ids)) {
                throw new InvalidArgumentException('MongoDB $in requires a list.');
            }
            if ($ids !== null && in_array($key, $ids, true)) {
                unset($this->docs[$key]);
            } elseif (isset($filter['ns']) && ($doc['ns'] ?? null) === $filter['ns']) {
                unset($this->docs[$key]);
            }
        }
    }

    /**
     * @param array{_id:string} $filter
     */
    public function deleteOne(array $filter): void
    {
        unset($this->docs[$filter['_id']]);
    }

    /**
     * @param array{_id:string} $filter
     * @return array<string,mixed>|null
     */
    public function findOne(array $filter): ?array
    {
        return $this->docs[$filter['_id']] ?? null;
    }

    /**
     * @param array{_id:string} $filter
     * @return array<string,mixed>|null
     */
    public function findOneAndDelete(array $filter): ?array
    {
        $id = $filter['_id'];
        $document = $this->docs[$id] ?? null;
        unset($this->docs[$id]);

        return $document;
    }

    /**
     * @param array{_id:array{'$in':list<string>}} $filter
     * @return list<array<string, mixed>>
     */
    public function find(array $filter): array
    {
        $this->findCalls++;
        $ids = $filter['_id']['$in'];

        return array_values(array_filter(
            $this->docs,
            static fn(array $doc): bool => in_array($doc['_id'] ?? null, $ids, true),
        ));
    }

    /**
     * @param array{_id:string,...<string,mixed>} $document
     * @throws RuntimeException
     */
    public function insertOne(array $document): object
    {
        $id = $document['_id'];
        if (isset($this->docs[$id])) {
            throw new RuntimeException('E11000 duplicate key', 11000);
        }
        $this->docs[$id] = $document;

        return new class
        {
            public function getInsertedCount(): int
            {
                return 1;
            }
        };
    }

    /**
     * @param array{_id:string,payload?:mixed} $filter
     * @param array{'$setOnInsert'?:array<string,mixed>,'$set'?:array<string,mixed>,'$inc'?:array<string,int>} $update
     * @param array{upsert?:bool} $options
     * @throws InvalidArgumentException
     */
    public function updateOne(array $filter, array $update, array $options = []): object
    {
        $id = $filter['_id'];
        $existing = $this->docs[$id] ?? null;
        if (array_key_exists('payload', $filter)
            && ($existing === null || ($existing['payload'] ?? null) !== $filter['payload'])) {
            return $this->writeResult(0);
        }
        if ($existing === null && ($options['upsert'] ?? false) !== true) {
            return $this->writeResult(0);
        }

        $matched = $existing === null ? 0 : 1;
        $document = $existing ?? ['_id' => $id];
        $onInsert = $update['$setOnInsert'] ?? [];
        $set = $update['$set'] ?? [];
        $increments = $update['$inc'] ?? [];
        $document = [...$document, ...$onInsert, ...$set];
        foreach ($increments as $field => $amount) {
            $current = $document[$field] ?? 0;
            if (!is_int($current)) {
                throw new InvalidArgumentException('MongoDB test increment requires an integer field.');
            }
            $document[$field] = $current + $amount;
        }
        $this->docs[$id] = $document;

        return $this->writeResult($matched);
    }

    /**
     * @param list<array{updateOne:array{array{_id:string,payload?:mixed},array{'$setOnInsert'?:array<string,mixed>,'$set'?:array<string,mixed>,'$inc'?:array<string,int>},array{upsert?:bool}}}> $operations
     * @param array{ordered?:bool} $options
     * @throws InvalidArgumentException
     */
    public function bulkWrite(array $operations, array $options = []): void
    {
        $this->bulkWrites++;
        unset($options);
        foreach ($operations as $operation) {
            [$filter, $update, $writeOptions] = $operation['updateOne'];
            $this->updateOne($filter, $update, $writeOptions);
        }
    }

    private function writeResult(int $matched): object
    {
        return new class($matched)
        {
            public function __construct(private readonly int $matched) {}

            public function getMatchedCount(): int
            {
                return $this->matched;
            }
        };
    }
}
