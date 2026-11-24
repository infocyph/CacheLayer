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

    public function deleteMany(array $filter): void
    {
        foreach ($this->docs as $key => $doc) {
            $ids = $filter['_id']['$in'] ?? null;
            if (is_array($ids) && !array_is_list($ids)) {
                throw new InvalidArgumentException('MongoDB $in requires a list.');
            }
            if (is_array($ids) && in_array($key, $ids, true)) {
                unset($this->docs[$key]);
            } elseif (isset($filter['ns']) && ($doc['ns'] ?? null) === $filter['ns']) {
                unset($this->docs[$key]);
            }
        }
    }

    public function deleteOne(array $filter): void
    {
        unset($this->docs[$filter['_id']]);
    }

    public function findOne(array $filter): ?array
    {
        return $this->docs[$filter['_id']] ?? null;
    }

    public function findOneAndDelete(array $filter): ?array
    {
        $id = $filter['_id'];
        $document = $this->docs[$id] ?? null;
        unset($this->docs[$id]);

        return $document;
    }

    /** @return list<array<string, mixed>> */
    public function find(array $filter): array
    {
        $this->findCalls++;
        $ids = $filter['_id']['$in'] ?? [];

        return array_values(array_filter(
            $this->docs,
            static fn(array $doc): bool => in_array($doc['_id'] ?? null, $ids, true),
        ));
    }

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

    public function updateOne(array $filter, array $update, array $options = []): object
    {
        $id = $filter['_id'];
        $existing = $this->docs[$id] ?? null;
        if (array_key_exists('payload', $filter)
            && (!is_array($existing) || ($existing['payload'] ?? null) !== $filter['payload'])) {
            return $this->writeResult(0);
        }
        if ($existing === null && !($options['upsert'] ?? false)) {
            return $this->writeResult(0);
        }

        $matched = $existing === null ? 0 : 1;
        $document = $existing ?? ['_id' => $id];
        $onInsert = $update['$setOnInsert'] ?? [];
        $set = $update['$set'] ?? [];
        $increments = $update['$inc'] ?? [];
        if (!is_array($onInsert) || !is_array($set) || !is_array($increments)) {
            throw new InvalidArgumentException('Invalid MongoDB update document.');
        }
        $document = [...$document, ...$onInsert, ...$set];
        foreach ($increments as $field => $amount) {
            $document[$field] = (int) ($document[$field] ?? 0) + (int) $amount;
        }
        $this->docs[$id] = $document;

        return $this->writeResult($matched);
    }

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
