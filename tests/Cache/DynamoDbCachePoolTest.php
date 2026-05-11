<?php

use Aws\DynamoDb\DynamoDbClient;
use Infocyph\CacheLayer\Cache\Adapter\DynamoDbCacheAdapter;
use Infocyph\CacheLayer\Cache\Cache;

/**
 * @return array{client: object, table: string, namespace: string}|null
 */
function dynamodbLocalIntegrationContext(): ?array
{
    static $context = false;

    if ($context !== false) {
        return $context;
    }

    if (!class_exists(DynamoDbClient::class)) {
        $context = null;

        return null;
    }

    $endpoint = getenv('IC_DYNAMODB_ENDPOINT') ?: getenv('CACHELAYER_DYNAMODB_ENDPOINT') ?: 'http://127.0.0.1:8000';
    $region = getenv('IC_DYNAMODB_REGION') ?: 'us-east-1';
    $key = getenv('IC_DYNAMODB_ACCESS_KEY_ID') ?: getenv('IC_SERVICE_USERNAME') ?: 'cachelayer';
    $secret = getenv('IC_DYNAMODB_SECRET_ACCESS_KEY') ?: getenv('IC_SERVICE_PASSWORD') ?: 'cachelayer';
    $table = getenv('IC_DYNAMODB_TABLE') ?: getenv('CACHELAYER_DYNAMODB_TABLE') ?: 'cachelayer_entries';

    $client = new DynamoDbClient([
        'version' => 'latest',
        'region' => $region,
        'endpoint' => $endpoint,
        'credentials' => [
            'key' => $key,
            'secret' => $secret,
        ],
        'http' => [
            'connect_timeout' => 1.5,
            'timeout' => 3.0,
        ],
    ]);

    try {
        $client->listTables(['Limit' => 1]);
    } catch (Throwable) {
        $context = null;

        return null;
    }

    try {
        $client->describeTable(['TableName' => $table]);
    } catch (Throwable) {
        try {
            $client->createTable([
                'TableName' => $table,
                'AttributeDefinitions' => [
                    [
                        'AttributeName' => 'ckey',
                        'AttributeType' => 'S',
                    ],
                ],
                'KeySchema' => [
                    [
                        'AttributeName' => 'ckey',
                        'KeyType' => 'HASH',
                    ],
                ],
                // Works with DynamoDB Local across broader versions than PAY_PER_REQUEST.
                'ProvisionedThroughput' => [
                    'ReadCapacityUnits' => 1,
                    'WriteCapacityUnits' => 1,
                ],
            ]);
        } catch (Throwable) {
            $context = null;

            return null;
        }
    }

    for ($attempt = 0; $attempt < 20; $attempt++) {
        try {
            $status = $client->describeTable(['TableName' => $table])['Table']['TableStatus'] ?? null;
            if ($status === 'ACTIVE') {
                break;
            }
        } catch (Throwable) {
            // Continue polling until timeout.
        }

        usleep(250_000);
    }

    $context = [
        'client' => $client,
        'table' => $table,
        'namespace' => 'ddb-live-tests',
    ];

    return $context;
}

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

test('dynamodb local integration stores and retrieves values', function () {
    $context = dynamodbLocalIntegrationContext();

    if ($context === null) {
        $this->markTestSkipped('DynamoDB Local integration unavailable (sdk or service missing).');
    }

    $cache = Cache::dynamoDb($context['namespace'], $context['table'], $context['client']);
    $cache->clear();

    expect($cache->set('live-key', 'live-value'))->toBeTrue()
        ->and($cache->get('live-key'))->toBe('live-value');
});

test('dynamodb local integration clears namespace entries', function () {
    $context = dynamodbLocalIntegrationContext();

    if ($context === null) {
        $this->markTestSkipped('DynamoDB Local integration unavailable (sdk or service missing).');
    }

    $cache = Cache::dynamoDb($context['namespace'], $context['table'], $context['client']);
    $cache->set('live-a', 1);
    $cache->set('live-b', 2);
    $cache->clear();

    expect($cache->count())->toBe(0);
});
