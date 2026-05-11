<?php

use Infocyph\CacheLayer\Cache\Adapter\MongoDbCacheAdapter;
use Infocyph\CacheLayer\Cache\Cache;

beforeEach(function () {
    $this->collection = new class
    {
        /** @var array<string, array<string, mixed>> */
        public array $docs = [];

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
                if (($doc['ns'] ?? null) === ($filter['ns'] ?? null)) {
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

        public function updateOne(array $filter, array $update, array $options = []): void
        {
            unset($options);
            $this->docs[$filter['_id']] = $update['$set'];
        }
    };

    $this->cache = new Cache(new MongoDbCacheAdapter($this->collection, 'mongo-tests'));
});

test('mongo adapter stores and retrieves values', function () {
    $this->cache->set('k', 'value');

    expect($this->cache->get('k'))->toBe('value')
        ->and($this->cache->count())->toBe(1);
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
