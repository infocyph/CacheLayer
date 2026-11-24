<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cache\Adapter\MongoDbCacheAdapter;
use Infocyph\CacheLayer\Cache\AtomicCacheInterface;
use Infocyph\CacheLayer\Cache\Cache;

final class CacheLayerMongoCollectionDouble
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
        $document = [...$document, ...($update['$setOnInsert'] ?? []), ...($update['$set'] ?? [])];
        foreach ($update['$inc'] ?? [] as $field => $amount) {
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

$mongoFixture = new class {
    public CacheLayerMongoCollectionDouble $collection;

    public Cache $cache;
};

beforeEach(function () use ($mongoFixture): void {
    $mongoFixture->collection = new CacheLayerMongoCollectionDouble();

    $mongoFixture->cache = new Cache(new MongoDbCacheAdapter($mongoFixture->collection, 'mongo-tests'));
});

test('mongo adapter stores and retrieves values', function () use ($mongoFixture): void {
    $mongoFixture->cache->set('k', 'value');

    expect($mongoFixture->cache->get('k'))->toBe('value');
});

test('mongo adapter honors ttl', function () use ($mongoFixture): void {
    $mongoFixture->cache->set('ttl', 'v', 1);
    usleep(2_000_000);

    expect($mongoFixture->cache->get('ttl'))->toBeNull();
});

test('mongodb cache factory accepts injected collection', function () use ($mongoFixture): void {
    $cache = Cache::mongodb('mongo-tests', $mongoFixture->collection);
    $cache->set('f', 'ok');

    expect($cache->get('f'))->toBe('ok');
});

test('mongodb uses one native bulk write and one $in read', function () use ($mongoFixture): void {
    $mongoFixture->cache->setMultiple(['a' => 1, 'b' => 2, 'c' => 3]);
    $writes = $mongoFixture->collection->bulkWrites;
    $reads = $mongoFixture->collection->findCalls;

    expect($mongoFixture->cache->getMultiple(['c', 'a', 'missing']))
        ->toBe(['c' => 3, 'a' => 1, 'missing' => null])
        ->and($mongoFixture->collection->bulkWrites)->toBe($writes)
        ->and($mongoFixture->collection->findCalls)->toBe($reads + 1)
        ->and($writes)->toBeGreaterThanOrEqual(1);
});

test('mongodb exposes atomic cache capability with one-winner semantics', function () use ($mongoFixture): void {
    $atomic = $mongoFixture->cache->atomic() ?? throw new \RuntimeException('The test requires atomic cache capability.');

    expect($atomic->setIfAbsent('claim', 'first', 30))->toBeTrue()
        ->and($atomic->setIfAbsent('claim', 'second', 30))->toBeFalse()
        ->and($mongoFixture->cache->get('claim'))->toBe('first');
});

test('mongodb atomic compare-and-set uses strict live-value semantics', function () use ($mongoFixture): void {
    $atomic = $mongoFixture->cache->atomic() ?? throw new \RuntimeException('The test requires atomic cache capability.');
    $mongoFixture->cache->set('cas', 1, 30);

    expect($atomic->compareAndSet('cas', '1', 2, 30))->toBeFalse()
        ->and($atomic->compareAndSet('cas', 1, 2, 30))->toBeTrue()
        ->and($atomic->compareAndSet('cas', 1, 3, 30))->toBeFalse()
        ->and($mongoFixture->cache->get('cas'))->toBe(2);
});

test('mongodb atomic compare-and-set rejects tagged state', function () use ($mongoFixture): void {
    $atomic = $mongoFixture->cache->atomic() ?? throw new \RuntimeException('The test requires atomic cache capability.');
    $mongoFixture->cache->setTagged('tagged', 'v1', ['group'], 30);

    expect($atomic->compareAndSet('tagged', 'v1', 'v2', 30))->toBeFalse()
        ->and($mongoFixture->cache->get('tagged'))->toBe('v1');
});

test('mongodb atomic compare-and-set replacement honors ttl', function () use ($mongoFixture): void {
    $atomic = $mongoFixture->cache->atomic() ?? throw new \RuntimeException('The test requires atomic cache capability.');
    $mongoFixture->cache->set('cas-ttl', 'v1', 30);

    expect($atomic->compareAndSet('cas-ttl', 'v1', 'v2', 1))->toBeTrue();
    usleep(2_000_000);

    expect($mongoFixture->cache->get('cas-ttl'))->toBeNull();
});

test('mongodb atomic set replaces expired state', function () use ($mongoFixture): void {
    $atomic = $mongoFixture->cache->atomic() ?? throw new \RuntimeException('The test requires atomic cache capability.');
    $mongoFixture->cache->set('claim', 'old', 1);
    usleep(2_000_000);

    expect($atomic->setIfAbsent('claim', 'new', 30))->toBeTrue()
        ->and($mongoFixture->cache->get('claim'))->toBe('new');
});

test('mongodb atomic consume returns one live value', function () use ($mongoFixture): void {
    $atomic = $mongoFixture->cache->atomic() ?? throw new \RuntimeException('The test requires atomic cache capability.');
    $mongoFixture->cache->set('consume', ['ok' => true], 30);

    expect($atomic->getAndDelete('consume', 'missing'))->toBe(['ok' => true])
        ->and($atomic->getAndDelete('consume', 'missing'))->toBe('missing');
});

test('mongodb atomic set can reclaim tag-invalidated state', function () use ($mongoFixture): void {
    $atomic = $mongoFixture->cache->atomic() ?? throw new \RuntimeException('The test requires atomic cache capability.');
    $mongoFixture->cache->setTagged('claim', 'old', ['group'], 30);
    $mongoFixture->cache->invalidateTag('group');

    expect($atomic->setIfAbsent('claim', 'new', 30))->toBeTrue()
        ->and($mongoFixture->cache->get('claim'))->toBe('new');
});


test('mongodb atomic consume discards deferred overlays without resurrection', function () use ($mongoFixture): void {
    $atomic = $mongoFixture->cache->atomic() ?? throw new \RuntimeException('The test requires atomic cache capability.');

    expect($mongoFixture->cache->set('deferred-consume', 'stored'))->toBeTrue()
        ->and($mongoFixture->cache->saveDeferred($mongoFixture->cache->getItem('deferred-consume')->set('pending')))->toBeTrue()
        ->and($atomic->getAndDelete('deferred-consume', 'missing'))->toBe('stored')
        ->and($atomic->getAndDelete('deferred-consume', 'missing'))->toBe('missing')
        ->and($mongoFixture->cache->commit())->toBeTrue()
        ->and($mongoFixture->cache->get('deferred-consume'))->toBeNull();
});

test('mongo adapter accepts associative PSR-6 delete key arrays', function () use ($mongoFixture): void {
    $adapter = new MongoDbCacheAdapter($mongoFixture->collection, 'mongo-associative');
    $cache = new Cache($adapter);
    expect($cache->setMultiple(['a' => 1, 'b' => 2, 'c' => 3]))->toBeTrue()
        ->and($adapter->deleteItems(['first' => 'a', 'second' => 'b']))->toBeTrue()
        ->and($cache->has('a'))->toBeFalse()
        ->and($cache->has('b'))->toBeFalse()
        ->and($cache->get('c'))->toBe(3);
});
