<?php

use Infocyph\CacheLayer\Cache\Adapter\DynamoDbCacheAdapter;
use Infocyph\CacheLayer\Cache\Cache;

beforeEach(function () {
    $this->client = new class
    {
        /** @var array<string, array<string, mixed>> */
        private array $items = [];

        public function batchWriteItem(array $params): array
        {
            foreach ($params['RequestItems'] as $requests) {
                foreach ($requests as $request) {
                    $key = $request['DeleteRequest']['Key']['ckey']['S'];
                    unset($this->items[$key]);
                }
            }

            return [];
        }

        public function deleteItem(array $params): array
        {
            $key = $params['Key']['ckey']['S'];
            unset($this->items[$key]);

            return [];
        }

        public function getItem(array $params): array
        {
            $key = $params['Key']['ckey']['S'];

            return isset($this->items[$key]) ? ['Item' => $this->items[$key]] : [];
        }

        public function putItem(array $params): array
        {
            $key = $params['Item']['ckey']['S'];
            $this->items[$key] = $params['Item'];

            return [];
        }

        public function scan(array $params): array
        {
            $ns = $params['ExpressionAttributeValues'][':ns']['S'] ?? null;
            $now = isset($params['ExpressionAttributeValues'][':now']['N'])
                ? (int) $params['ExpressionAttributeValues'][':now']['N']
                : null;

            $filtered = [];
            foreach ($this->items as $item) {
                if (($item['ns']['S'] ?? null) !== $ns) {
                    continue;
                }

                if ($now !== null) {
                    $expires = isset($item['expires']['N']) ? (int) $item['expires']['N'] : null;
                    if ($expires !== null && $expires <= $now) {
                        continue;
                    }
                }

                $filtered[] = $item;
            }

            if (($params['Select'] ?? null) === 'COUNT') {
                return ['Count' => count($filtered)];
            }

            $projected = [];
            foreach ($filtered as $item) {
                $projected[] = ['ckey' => ['S' => $item['ckey']['S']]];
            }

            return ['Items' => $projected];
        }
    };

    $this->cache = new Cache(new DynamoDbCacheAdapter($this->client, 'cachelayer_entries', 'ddb-tests'));
});

test('dynamodb adapter stores and retrieves values', function () {
    $this->cache->set('k', 'value');

    expect($this->cache->get('k'))->toBe('value')
        ->and($this->cache->count())->toBe(1);
});

test('dynamodb adapter clears namespace entries', function () {
    $this->cache->set('a', 1);
    $this->cache->set('b', 2);

    $this->cache->clear();

    expect($this->cache->count())->toBe(0);
});

test('dynamodb cache factory accepts injected client', function () {
    $cache = Cache::dynamoDb('ddb-tests', 'cachelayer_entries', $this->client);
    $cache->set('x', 'X');

    expect($cache->get('x'))->toBe('X');
});
