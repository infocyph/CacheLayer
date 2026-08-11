<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cache\Adapter;

use Infocyph\CacheLayer\Cache\CacheInput;
use Infocyph\CacheLayer\Cache\Item\CacheItem;
use Psr\Cache\CacheItemInterface;
use RuntimeException;

final class MongoDbCacheAdapter extends AbstractCacheAdapter implements TagGenerationCacheInterface
{
    private readonly string $ns;

    public function __construct(
        private readonly object $collection,
        string $namespace = 'default',
    ) {
        $this->ns = CacheInput::namespace($namespace);

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

        $payload = $this->binaryString($row['payload'] ?? null);

        return $this->genericFromBlobWithInvalidator(
            $key,
            $payload,
            fn(): bool => $this->deleteItem($key),
        );
    }

    /**
     * @param list<string> $tags
     * @return array<string, string>
     */
    #[\Override]
    public function getTagGenerations(array $tags): array
    {
        $generations = $this->readTagGenerations($tags);
        $missing = [];
        foreach ($tags as $tag) {
            if (!isset($generations[$tag])) {
                $missing[$tag] = self::newGeneration();
            }
        }
        if ($missing !== [] && !$this->storeTagGenerations($missing)) {
            throw new RuntimeException('Unable to initialize MongoDB tag generations.');
        }

        return $generations + $missing;
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
            $payload = is_array($row) ? $this->binaryString($row['payload'] ?? null) : null;
            $item = $this->genericFromBlobWithInvalidator($key, $payload, static fn(): bool => true);
            $items[$key] = $item;
            if (is_array($row) && !$item->isHit()) {
                $stale[] = $key;
            }
        }
        $this->deleteItems($stale);

        return $items;
    }

    /** @param list<string> $tags */
    #[\Override]
    public function readTagGenerations(array $tags): array
    {
        if ($tags === []) {
            return [];
        }
        $documents = $this->collection->find([
            '_id' => ['$in' => array_map($this->mapTag(...), $tags)],
        ]);
        if (!is_iterable($documents)) {
            throw new RuntimeException('MongoDB find() must return an iterable result.');
        }
        $generations = [];
        foreach ($documents as $document) {
            $row = AdapterValueNormalizer::fromJsonOrArrayLike($document);
            $tag = is_array($row) ? ($row['tag'] ?? null) : null;
            $generation = is_array($row) ? self::normalizeGeneration($row['generation'] ?? null) : null;
            if (is_string($tag) && $generation !== null) {
                $generations[$tag] = $generation;
            }
        }

        return $generations;
    }

    /** @param list<string> $tags */
    #[\Override]
    public function rotateTagGenerations(array $tags): bool
    {
        $generations = [];
        foreach ($tags as $tag) {
            $generations[$tag] = self::newGeneration();
        }

        return $this->storeTagGenerations($generations);
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
                        'payload' => $this->binaryValue($this->encodeItem($saveItem, $expires['expiresAt'])),
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
                    'payload' => $this->binaryValue($this->encodeItem($item, $expiration['expiresAt'])),
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

    /** @param array<string, string> $generations */
    #[\Override]
    public function storeTagGenerations(array $generations): bool
    {
        $operations = [];
        foreach ($generations as $tag => $generation) {
            if (!self::isGeneration($generation)) {
                return false;
            }
            $operations[] = ['updateOne' => [
                ['_id' => $this->mapTag($tag)],
                [
                    '$set' => [
                        'ns' => $this->ns,
                        'kind' => 'metadata',
                        'tag' => $tag,
                        'generation' => strtolower($generation),
                    ],
                ],
                ['upsert' => true],
            ]];
        }
        if ($operations !== []) {
            $this->collection->bulkWrite($operations, ['ordered' => false]);
        }

        return true;
    }

    private function binaryString(mixed $value): ?string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_object($value) && is_callable([$value, 'getData'])) {
            $data = $value->getData();

            return is_string($data) ? $data : null;
        }

        return null;
    }

    private function binaryValue(string $value): mixed
    {
        if (class_exists(\MongoDB\BSON\Binary::class)) {
            return new \MongoDB\BSON\Binary($value, \MongoDB\BSON\Binary::TYPE_GENERIC);
        }

        return $value;
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
