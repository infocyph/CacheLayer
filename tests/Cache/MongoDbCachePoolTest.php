<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cache\Adapter\MongoDbCacheAdapter;
use Infocyph\CacheLayer\Cache\Cache;

beforeEach(function () {
    $this->collection = new class
    {
        /** @var array<string, array<string, mixed>> */
        public array $docs = [];

        public int $bulkWrites = 0;

        public int $findCalls = 0;

        public function countDocuments(array $filter): int
        {
            $count = 0;
            $now = time();

            foreach ($this->docs as $doc) {
                if (($doc['ns'] ?? null) !== ($filter['ns'] ?? null)) {
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

        public function updateOne(array $filter, array $update, array $options = []): void
        {
            unset($options);
            $id = $filter['_id'];
            $document = $this->docs[$id] ?? ['_id' => $id];
            $document = [...$document, ...($update['$setOnInsert'] ?? []), ...($update['$set'] ?? [])];
            foreach ($update['$inc'] ?? [] as $field => $amount) {
                $document[$field] = (int) ($document[$field] ?? 0) + (int) $amount;
            }
            $this->docs[$id] = $document;
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
    };

    $this->cache = new Cache(new MongoDbCacheAdapter($this->collection, 'mongo-tests'));
});

test('mongo adapter stores and retrieves values', function () {
    $this->cache->set('k', 'value');

    expect($this->cache->get('k'))->toBe('value');
});

test('mongo adapter honors ttl', function () {
    $this->cache->set('ttl', 'v', 1);
    usleep(2_000_000);

    expect($this->cache->get('ttl'))->toBeNull();
});

test('mongodb cache factory accepts injected collection', function () {
    $cache = Cache::mongodb('mongo-tests', $this->collection);
    $cache->set('f', 'ok');

    expect($cache->get('f'))->toBe('ok');
});

test('mongodb uses one native bulk write and one $in read', function () {
    $this->cache->setMultiple(['a' => 1, 'b' => 2, 'c' => 3]);
    $writes = $this->collection->bulkWrites;
    $reads = $this->collection->findCalls;

    expect($this->cache->getMultiple(['c', 'a', 'missing']))
        ->toBe(['c' => 3, 'a' => 1, 'missing' => null])
        ->and($this->collection->bulkWrites)->toBe($writes)
        ->and($this->collection->findCalls)->toBe($reads + 1)
        ->and($writes)->toBeGreaterThanOrEqual(1);
});
