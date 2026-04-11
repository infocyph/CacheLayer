<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cache\Adapter;

use Infocyph\CacheLayer\Cache\Item\GenericCacheItem;
use Psr\Cache\CacheItemInterface;
use RuntimeException;

final class MongoDbCacheAdapter extends AbstractCacheAdapter
{
    private readonly string $ns;

    public function __construct(
        private readonly object $collection,
        string $namespace = 'default',
    ) {
        $this->ns = sanitize_cache_ns($namespace);

        foreach (['findOne', 'updateOne', 'deleteOne', 'deleteMany', 'countDocuments'] as $method) {
            if (!method_exists($this->collection, $method)) {
                throw new RuntimeException(
                    sprintf('MongoDbCacheAdapter requires collection method `%s()`.', $method),
                );
            }
        }
    }

    public static function fromClient(
        object $client,
        string $database = 'cachelayer',
        string $collection = 'entries',
        string $namespace = 'default',
    ): self {
        if (!method_exists($client, 'selectCollection')) {
            throw new RuntimeException('Mongo client must expose selectCollection().');
        }

        /** @var object $selected */
        $selected = $client->selectCollection($database, $collection);
        return new self($selected, $namespace);
    }

    public function clear(): bool
    {
        $this->collection->deleteMany(['ns' => $this->ns]);
        $this->deferred = [];
        return true;
    }

    public function count(): int
    {
        return (int) $this->collection->countDocuments([
            'ns' => $this->ns,
            '$or' => [
                ['expires' => null],
                ['expires' => ['$gt' => time()]],
            ],
        ]);
    }

    public function deleteItem(string $key): bool
    {
        $this->collection->deleteOne(['_id' => $this->map($key)]);
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
        $doc = $this->collection->findOne(['_id' => $this->map($key)]);
        $row = $this->toArray($doc);

        if ($row === null || !is_string($row['payload'] ?? null)) {
            return new GenericCacheItem($this, $key);
        }

        $expiresAt = is_numeric($row['expires'] ?? null) ? (int) $row['expires'] : null;
        if (CachePayloadCodec::isExpired($expiresAt)) {
            $this->deleteItem($key);
            return new GenericCacheItem($this, $key);
        }

        $blob = base64_decode($row['payload'], true);
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

        $this->collection->updateOne(
            ['_id' => $this->map($item->getKey())],
            [
                '$set' => [
                    'ns' => $this->ns,
                    'payload' => base64_encode(CachePayloadCodec::encode($item->get(), $expires['expiresAt'])),
                    'expires' => $expires['expiresAt'],
                ],
            ],
            ['upsert' => true],
        );

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

        if ($value instanceof \JsonSerializable) {
            $json = $value->jsonSerialize();
            return is_array($json) ? $json : null;
        }

        if ($value instanceof \ArrayAccess && $value instanceof \Traversable) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[(string) $k] = $v;
            }

            return $out;
        }

        return null;
    }
}
