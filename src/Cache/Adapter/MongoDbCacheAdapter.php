<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cache\Adapter;

use Infocyph\CacheLayer\Cache\Item\CacheItem;
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

        foreach (['findOne', 'find', 'updateOne', 'bulkWrite', 'deleteOne', 'deleteMany', 'countDocuments'] as $method) {
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
        $count = $this->collection->countDocuments([
            'ns' => $this->ns,
            'kind' => 'data',
            '$or' => [
                ['expires' => null],
                ['expires' => ['$gt' => time()]],
            ],
        ]);

        return is_numeric($count) ? max(0, (int) $count) : 0;
    }

    public function deleteItem(string $key): bool
    {
        $this->collection->deleteOne(['_id' => $this->mapData($key)]);

        return true;
    }

    /**
     * @param array $keys The keys argument.
     * @phpstan-param list<string> $keys
     */
    public function deleteItems(array $keys): bool
    {
        if ($keys !== []) {
            $this->collection->deleteMany([
                '_id' => ['$in' => array_map($this->mapData(...), $keys)],
            ]);
        }

        return true;
    }

    public function getItem(string $key): CacheItem
    {
        $doc = $this->collection->findOne(['_id' => $this->mapData($key)]);
        $row = AdapterValueNormalizer::fromJsonOrArrayLike($doc);

        if ($row === null) {
            return $this->genericMiss($key);
        }

        $payload = $row['payload'] ?? null;

        return $this->genericFromBase64($key, is_string($payload) ? $payload : null);
    }

    /**
     * @param list<string> $tags
     * @return array<string, int>
     */
    #[\Override]
    public function getTagVersions(array $tags): array
    {
        $versions = array_fill_keys($tags, 0);
        if ($tags === []) {
            return $versions;
        }
        $documents = $this->collection->find([
            '_id' => ['$in' => array_map($this->mapTag(...), $tags)],
        ]);
        if (!is_iterable($documents)) {
            throw new RuntimeException('MongoDB find() must return an iterable result.');
        }
        foreach ($documents as $document) {
            $row = AdapterValueNormalizer::fromJsonOrArrayLike($document);
            $tag = is_array($row) ? ($row['tag'] ?? null) : null;
            $version = is_array($row) ? ($row['version'] ?? null) : null;
            if (is_string($tag) && is_numeric($version)) {
                $versions[$tag] = max(0, (int) $version);
            }
        }

        return $versions;
    }

    public function hasItem(string $key): bool
    {
        $count = $this->collection->countDocuments([
            '_id' => $this->mapData($key),
            '$or' => [
                ['expires' => null],
                ['expires' => ['$gt' => time()]],
            ],
        ]);

        return is_numeric($count) && (int) $count > 0;
    }

    /** @param list<string> $tags */
    #[\Override]
    public function incrementTagVersions(array $tags): bool
    {
        $operations = [];
        foreach ($tags as $tag) {
            $operations[] = ['updateOne' => [
                ['_id' => $this->mapTag($tag)],
                [
                    '$setOnInsert' => ['ns' => $this->ns, 'kind' => 'metadata', 'tag' => $tag],
                    '$inc' => ['version' => 1],
                ],
                ['upsert' => true],
            ]];
        }
        if ($operations !== []) {
            $this->collection->bulkWrite($operations, ['ordered' => false]);
        }

        return true;
    }

    /**
     * @param array $keys The keys argument.
     * @phpstan-param list<string> $keys
     * @phpstan-return array<string, CacheItem>
     */
    public function multiFetch(array $keys): array
    {
        $ids = array_map($this->mapData(...), $keys);
        $documents = $keys === [] ? [] : $this->collection->find(['_id' => ['$in' => $ids]]);
        if (!is_iterable($documents)) {
            throw new RuntimeException('MongoDB find() must return an iterable result.');
        }
        $byId = [];
        foreach ($documents as $document) {
            $row = AdapterValueNormalizer::fromJsonOrArrayLike($document);
            if (is_array($row) && is_string($row['_id'] ?? null)) {
                $byId[$row['_id']] = $row;
            }
        }

        $items = [];
        $stale = [];
        foreach ($keys as $key) {
            $row = $byId[$this->mapData($key)] ?? null;
            $payload = is_array($row) && is_string($row['payload'] ?? null) ? $row['payload'] : null;
            $item = $this->genericFromBase64WithInvalidator($key, $payload, static fn(): bool => true);
            $items[$key] = $item;
            if (is_array($row) && !$item->isHit()) {
                $stale[] = $key;
            }
        }
        $this->deleteItems($stale);

        return $items;
    }

    public function save(CacheItemInterface $item): bool
    {
        return $this->saveEncoded($item, function (CacheItemInterface $saveItem, array $expires): bool {
            $this->collection->updateOne(
                ['_id' => $this->mapData($saveItem->getKey())],
                [
                    '$set' => [
                        'ns' => $this->ns,
                        'kind' => 'data',
                        'payload' => base64_encode($this->encodeItem($saveItem, $expires['expiresAt'])),
                        'expires' => $expires['expiresAt'],
                    ],
                ],
                ['upsert' => true],
            );

            return true;
        });
    }

    /** @param array<string, CacheItemInterface> $items */
    public function saveItems(array $items): bool
    {
        $operations = [];
        $expired = [];
        foreach ($items as $item) {
            if (!$this->supportsItem($item)) {
                return false;
            }
            $expiration = CachePayloadCodec::expirationFromItem($item);
            if ($expiration['ttl'] !== null && $expiration['ttl'] <= 0) {
                $expired[] = $item->getKey();

                continue;
            }
            $operations[] = ['updateOne' => [
                ['_id' => $this->mapData($item->getKey())],
                ['$set' => [
                    'ns' => $this->ns,
                    'kind' => 'data',
                    'payload' => base64_encode($this->encodeItem($item, $expiration['expiresAt'])),
                    'expires' => $expiration['expiresAt'],
                ]],
                ['upsert' => true],
            ]];
        }
        $this->deleteItems($expired);
        if ($operations !== []) {
            $this->collection->bulkWrite($operations, ['ordered' => false]);
        }

        return true;
    }

    private function mapData(string $key): string
    {
        return $this->ns . ':d:' . $key;
    }

    private function mapTag(string $tag): string
    {
        return $this->ns . ':m:tag:' . $tag;
    }
}
