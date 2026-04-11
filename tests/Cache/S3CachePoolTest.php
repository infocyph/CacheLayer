<?php

use Infocyph\CacheLayer\Cache\Adapter\S3CacheAdapter;
use Infocyph\CacheLayer\Cache\Cache;

beforeEach(function () {
    $this->client = new class {
        /** @var array<string, string> */
        private array $objects = [];

        public function deleteObject(array $params): array
        {
            unset($this->objects[$params['Key']]);
            return [];
        }

        public function deleteObjects(array $params): array
        {
            foreach ($params['Delete']['Objects'] as $object) {
                unset($this->objects[$object['Key']]);
            }

            return [];
        }

        public function getObject(array $params): array
        {
            $key = $params['Key'];
            if (!array_key_exists($key, $this->objects)) {
                throw new RuntimeException('Not found');
            }

            return ['Body' => $this->objects[$key]];
        }

        public function listObjectsV2(array $params): array
        {
            $prefix = $params['Prefix'] ?? '';
            $rows = [];
            foreach (array_keys($this->objects) as $key) {
                if (str_starts_with($key, $prefix)) {
                    $rows[] = ['Key' => $key];
                }
            }

            return ['Contents' => $rows];
        }

        public function putObject(array $params): array
        {
            $this->objects[$params['Key']] = (string) $params['Body'];
            return [];
        }
    };

    $this->cache = new Cache(new S3CacheAdapter($this->client, 'bucket', 'cachelayer', 's3-tests'));
});

test('s3 adapter stores and retrieves values', function () {
    $this->cache->set('k', 'value');

    expect($this->cache->get('k'))->toBe('value')
        ->and($this->cache->count())->toBe(1);
});

test('s3 adapter clear removes namespace objects', function () {
    $this->cache->set('a', 1);
    $this->cache->set('b', 2);
    $this->cache->clear();

    expect($this->cache->count())->toBe(0);
});

test('s3 cache factory accepts injected client', function () {
    $cache = Cache::s3('s3-tests', 'bucket', $this->client);
    $cache->set('x', 'X');

    expect($cache->get('x'))->toBe('X');
});
