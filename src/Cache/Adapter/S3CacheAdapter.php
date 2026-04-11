<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cache\Adapter;

use Infocyph\CacheLayer\Cache\Item\GenericCacheItem;
use Psr\Cache\CacheItemInterface;
use RuntimeException;

final class S3CacheAdapter extends AbstractCacheAdapter
{
    private readonly string $keyPrefix;
    private readonly string $ns;

    public function __construct(
        private readonly object $client,
        private readonly string $bucket = 'cachelayer',
        string $prefix = 'cachelayer',
        string $namespace = 'default',
    ) {
        $this->ns = sanitize_cache_ns($namespace);
        $this->keyPrefix = trim($prefix, '/');

        foreach (['putObject', 'getObject', 'deleteObject', 'listObjectsV2', 'deleteObjects'] as $method) {
            if (!method_exists($this->client, $method)) {
                throw new RuntimeException(
                    sprintf('S3CacheAdapter requires client method `%s()`.', $method),
                );
            }
        }
    }

    public function clear(): bool
    {
        $keys = $this->listNamespaceKeys();
        foreach (array_chunk($keys, 1000) as $chunk) {
            $objects = array_map(fn(string $key): array => ['Key' => $key], $chunk);
            $this->client->deleteObjects([
                'Bucket' => $this->bucket,
                'Delete' => ['Objects' => $objects, 'Quiet' => true],
            ]);
        }

        $this->deferred = [];
        return true;
    }

    public function count(): int
    {
        $count = 0;
        foreach ($this->listNamespaceKeys() as $key) {
            $logicalKey = $this->logicalKeyFromObjectKey($key);
            if ($logicalKey === null) {
                continue;
            }

            if ($this->getItem($logicalKey)->isHit()) {
                $count++;
            }
        }

        return $count;
    }

    public function deleteItem(string $key): bool
    {
        $this->client->deleteObject([
            'Bucket' => $this->bucket,
            'Key' => $this->map($key),
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
        try {
            $result = $this->client->getObject([
                'Bucket' => $this->bucket,
                'Key' => $this->map($key),
            ]);
        } catch (\Throwable) {
            return new GenericCacheItem($this, $key);
        }

        $row = $this->toArray($result) ?? [];
        $body = $row['Body'] ?? null;
        if ($body instanceof \Stringable) {
            $body = (string) $body;
        }

        if (!is_string($body)) {
            return new GenericCacheItem($this, $key);
        }

        $record = CachePayloadCodec::decode($body);
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

        $this->client->putObject([
            'Bucket' => $this->bucket,
            'Key' => $this->map($item->getKey()),
            'Body' => CachePayloadCodec::encode($item->get(), $expires['expiresAt']),
            'ContentType' => 'application/octet-stream',
        ]);

        return true;
    }

    protected function supportsItem(CacheItemInterface $item): bool
    {
        return $item instanceof GenericCacheItem;
    }

    /**
     * @return list<string>
     */
    private function listNamespaceKeys(): array
    {
        $prefix = $this->namespacePrefix();
        $out = [];
        $token = null;

        do {
            $params = [
                'Bucket' => $this->bucket,
                'Prefix' => $prefix,
                'MaxKeys' => 1000,
            ];
            if (is_string($token) && $token !== '') {
                $params['ContinuationToken'] = $token;
            }

            $result = $this->toArray($this->client->listObjectsV2($params)) ?? [];
            foreach ($result['Contents'] ?? [] as $row) {
                if (is_array($row) && is_string($row['Key'] ?? null)) {
                    $out[] = $row['Key'];
                }
            }

            $token = is_string($result['NextContinuationToken'] ?? null)
                ? $result['NextContinuationToken']
                : null;
        } while ($token !== null);

        return $out;
    }

    private function logicalKeyFromObjectKey(string $objectKey): ?string
    {
        $prefix = $this->namespacePrefix();
        if (!str_starts_with($objectKey, $prefix)) {
            return null;
        }

        $name = substr($objectKey, strlen($prefix));
        $parts = explode('_', $name, 2);
        if (count($parts) !== 2) {
            return null;
        }

        $encoded = substr($parts[1], 0, -6);
        if ($encoded === '' || !str_ends_with($name, '.cache')) {
            return null;
        }

        return rawurldecode($encoded);
    }

    private function map(string $key): string
    {
        return $this->namespacePrefix() . hash('xxh128', $key) . '_' . rawurlencode($key) . '.cache';
    }

    private function namespacePrefix(): string
    {
        return $this->keyPrefix . '/' . $this->ns . '/';
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
