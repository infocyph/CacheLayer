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

            $result = $this->toArray($this->client->scan($params)) ?? [];
            foreach ($result['Items'] ?? [] as $item) {
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
        $result = $this->toArray($this->client->scan([
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

        return (int) ($result['Count'] ?? 0);
    }

    public function deleteItem(string $key): bool
    {
        $this->client->deleteItem([
            'TableName' => $this->table,
            'Key' => ['ckey' => ['S' => $this->map($key)]],
        ]);

        return true;
    }

    public function deleteItems(array $keys): bool
    {
        foreach ($keys as $key) {
            $this->deleteItem((string) $key);
        }

        return true;
    }

    public function getItem(string $key): GenericCacheItem
    {
        $result = $this->toArray($this->client->getItem([
            'TableName' => $this->table,
            'Key' => ['ckey' => ['S' => $this->map($key)]],
            'ConsistentRead' => true,
        ]));

        $row = isset($result['Item']) && is_array($result['Item']) ? $result['Item'] : null;
        if ($row === null) {
            return new GenericCacheItem($this, $key);
        }

        $payload = $row['payload']['S'] ?? null;
        if (!is_string($payload)) {
            $this->deleteItem($key);
            return new GenericCacheItem($this, $key);
        }

        $expiresAt = is_array($row['expires'] ?? null) && is_string($row['expires']['N'] ?? null)
            ? (int) $row['expires']['N']
            : null;
        if (CachePayloadCodec::isExpired($expiresAt)) {
            $this->deleteItem($key);
            return new GenericCacheItem($this, $key);
        }

        $blob = base64_decode($payload, true);
        if (!is_string($blob)) {
            $this->deleteItem($key);
            return new GenericCacheItem($this, $key);
        }

        $record = CachePayloadCodec::decode($blob);
        if ($record === null || CachePayloadCodec::isExpired($record['expires'])) {
            $this->deleteItem($key);
            return new GenericCacheItem($this, $key);
        }

        $item = new GenericCacheItem($this, $key);
        $item->set($record['value']);
        if ($record['expires'] !== null) {
            $item->expiresAt(CachePayloadCodec::toDateTime($record['expires']));
        }

        return $item;
    }

    public function hasItem(string $key): bool
    {
        return $this->getItem($key)->isHit();
    }

    public function multiFetch(array $keys): array
    {
        $items = [];
        foreach ($keys as $key) {
            $items[(string) $key] = $this->getItem((string) $key);
        }

        return $items;
    }

    public function save(CacheItemInterface $item): bool
    {
        if (!$this->supportsItem($item)) {
            return false;
        }

        $expires = CachePayloadCodec::expirationFromItem($item);
        if ($expires['ttl'] === 0) {
            return $this->deleteItem($item->getKey());
        }

        $itemMap = [
            'ckey' => ['S' => $this->map($item->getKey())],
            'ns' => ['S' => $this->ns],
            'payload' => ['S' => base64_encode(CachePayloadCodec::encode($item->get(), $expires['expiresAt']))],
        ];
        if ($expires['expiresAt'] !== null) {
            $itemMap['expires'] = ['N' => (string) $expires['expiresAt']];
        }

        $this->client->putItem([
            'TableName' => $this->table,
            'Item' => $itemMap,
        ]);

        return true;
    }

    protected function supportsItem(CacheItemInterface $item): bool
    {
        return $item instanceof GenericCacheItem;
    }

    private function map(string $key): string
    {
        return $this->ns . ':' . $key;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function toArray(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            return $value;
        }

        if ($value instanceof \ArrayAccess && $value instanceof \Traversable) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[(string) $k] = $v;
            }

            return $out;
        }

        if (method_exists($value, 'toArray')) {
            $arr = $value->toArray();
            return is_array($arr) ? $arr : null;
        }

        return null;
    }
}
