<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cache\Adapter;

use Infocyph\CacheLayer\Cache\Item\GenericCacheItem;
use Psr\Cache\CacheItemInterface;
use RuntimeException;

final class DynamoDbCacheAdapter extends AbstractCacheAdapter
{
    private readonly string $ns;

    public function __construct(
        private readonly object $client,
        private readonly string $table = 'cachelayer_entries',
        string $namespace = 'default',
    ) {
        $this->ns = sanitize_cache_ns($namespace);

        foreach (['getItem', 'putItem', 'deleteItem', 'scan', 'batchWriteItem'] as $method) {
            if (!method_exists($this->client, $method)) {
                throw new RuntimeException(
                    sprintf('DynamoDbCacheAdapter requires client method `%s()`.', $method),
                );
            }
        }
    }

    public function clear(): bool
    {
        $keys = [];
        $lastKey = null;

        do {
            $params = [
                'TableName' => $this->table,
                'FilterExpression' => '#ns = :ns',
                'ProjectionExpression' => '#k',
                'ExpressionAttributeNames' => [
                    '#ns' => 'ns',
                    '#k' => 'ckey',
                ],
                'ExpressionAttributeValues' => [
                    ':ns' => ['S' => $this->ns],
                ],
            ];

            if (is_array($lastKey)) {
                $params['ExclusiveStartKey'] = $lastKey;
            }

            $result = AdapterValueNormalizer::fromArrayLikeOrToArray($this->client->scan($params)) ?? [];
            $items = is_array($result['Items'] ?? null) ? $result['Items'] : [];
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }

                if (is_array($item['ckey'] ?? null) && is_string($item['ckey']['S'] ?? null)) {
                    $keys[] = $item['ckey']['S'];
                }
            }

            $lastKey = isset($result['LastEvaluatedKey']) && is_array($result['LastEvaluatedKey'])
                ? $result['LastEvaluatedKey']
                : null;
        } while ($lastKey !== null);

        foreach (array_chunk($keys, 25) as $batch) {
            $requests = array_map(
                fn(string $key): array => ['DeleteRequest' => ['Key' => ['ckey' => ['S' => $key]]]],
                $batch,
            );
            $this->client->batchWriteItem(['RequestItems' => [$this->table => $requests]]);
        }

        $this->deferred = [];

        return true;
    }

    public function count(): int
    {
        $result = AdapterValueNormalizer::fromArrayLikeOrToArray($this->client->scan([
            'TableName' => $this->table,
            'FilterExpression' => '#ns = :ns AND (attribute_not_exists(#exp) OR #exp > :now)',
            'Select' => 'COUNT',
            'ExpressionAttributeNames' => [
                '#ns' => 'ns',
                '#exp' => 'expires',
            ],
            'ExpressionAttributeValues' => [
                ':ns' => ['S' => $this->ns],
                ':now' => ['N' => (string) time()],
            ],
        ]));

        $count = $result['Count'] ?? 0;

        return is_numeric($count) ? max(0, (int) $count) : 0;
    }

    public function deleteItem(string $key): bool
    {
        $this->client->deleteItem([
            'TableName' => $this->table,
            'Key' => ['ckey' => ['S' => $this->map($key)]],
        ]);

        return true;
    }

    /**
     * @param list<string> $keys
     */
    public function deleteItems(array $keys): bool
    {
        foreach ($keys as $key) {
            $this->deleteItem((string) $key);
        }

        return true;
    }

    public function getItem(string $key): GenericCacheItem
    {
        $result = AdapterValueNormalizer::fromArrayLikeOrToArray($this->client->getItem([
            'TableName' => $this->table,
            'Key' => ['ckey' => ['S' => $this->map($key)]],
            'ConsistentRead' => true,
        ]));

        $row = isset($result['Item']) && is_array($result['Item']) ? $result['Item'] : null;
        if ($row === null) {
            return new GenericCacheItem($this, $key);
        }

        $payloadAttr = is_array($row['payload'] ?? null) ? $row['payload'] : null;
        $payload = is_array($payloadAttr) ? ($payloadAttr['S'] ?? null) : null;

        return $this->genericFromBase64($key, is_string($payload) ? $payload : null);
    }

    public function hasItem(string $key): bool
    {
        return $this->getItem($key)->isHit();
    }

    /**
     * @param list<string> $keys
     * @return array<string, GenericCacheItem>
     */
    public function multiFetch(array $keys): array
    {
        return $this->multiFetchItems($keys, $this->getItem(...));
    }

    public function save(CacheItemInterface $item): bool
    {
        return $this->saveEncoded($item, function (CacheItemInterface $saveItem, array $expires): bool {
            $itemMap = [
                'ckey' => ['S' => $this->map($saveItem->getKey())],
                'ns' => ['S' => $this->ns],
                'payload' => ['S' => base64_encode(CachePayloadCodec::encode($saveItem->get(), $expires['expiresAt']))],
            ];
            if ($expires['expiresAt'] !== null) {
                $itemMap['expires'] = ['N' => (string) $expires['expiresAt']];
            }

            $this->client->putItem([
                'TableName' => $this->table,
                'Item' => $itemMap,
            ]);

            return true;
        });
    }

    protected function supportsItem(CacheItemInterface $item): bool
    {
        return $item instanceof GenericCacheItem;
    }

    private function map(string $key): string
    {
        return $this->ns . ':' . $key;
    }
}
