<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cache\Adapter;

use Infocyph\CacheLayer\Cache\CacheInput;
use Infocyph\CacheLayer\Cache\CacheRecord;
use Infocyph\CacheLayer\Cache\Item\CacheItem;
use Psr\Cache\CacheItemInterface;
use RuntimeException;
use Throwable;

final class MongoDbCacheAdapter extends AbstractCacheAdapter implements AtomicCachePoolInterface, TagGenerationCacheInterface
{
    private readonly string $ns;

    public function __construct(
        private readonly object $collection,
        string $namespace = 'default',
    ) {
        $this->ns = CacheInput::namespace($namespace);

        foreach (
            [
                'findOne',
                'findOneAndDelete',
                'find',
                'insertOne',
                'updateOne',
                'bulkWrite',
                'deleteOne',
                'deleteMany',
                'countDocuments',
            ] as $method
        ) {
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

    public function atomicCompareAndSet(
        string $key,
        mixed $expected,
        CacheItemInterface $replacement,
    ): bool {
        if (!$this->supportsItem($replacement)) {
            return false;
        }

        $expiration = CachePayloadCodec::expirationFromItem($replacement);
        if ($expiration['ttl'] !== null && $expiration['ttl'] <= 0) {
            return false;
        }

        $id = $this->mapData($key);
        $row = AdapterValueNormalizer::fromJsonOrArrayLike(
            $this->collection->findOne(['_id' => $id]),
        );
        if (!is_array($row) || !array_key_exists('payload', $row)) {
            return false;
        }

        $record = $this->recordFromRow($row);
        if (!$record instanceof CacheRecord || $record->tags !== [] || $record->value !== $expected) {
            return false;
        }

        $result = $this->collection->updateOne(
            ['_id' => $id, 'payload' => $row['payload']],
            ['$set' => $this->atomicReplacement($replacement, $expiration['expiresAt'])],
        );

        return $this->matchedCount($result) === 1;
    }

    public function atomicGetAndDelete(string $key): CacheItemInterface
    {
        $document = $this->collection->findOneAndDelete(['_id' => $this->mapData($key)]);
        $row = AdapterValueNormalizer::fromJsonOrArrayLike($document);
        $record = is_array($row) ? $this->recordFromRow($row) : null;

        return $record instanceof CacheRecord
            ? $this->genericItemFromRecord($key, $record)
            : $this->genericMiss($key);
    }

    public function atomicSetIfAbsent(CacheItemInterface $item): bool
    {
        if (!$this->supportsItem($item)) {
            return false;
        }
        $expiration = CachePayloadCodec::expirationFromItem($item);
        if ($expiration['ttl'] !== null && $expiration['ttl'] <= 0) {
            return false;
        }

        $id = $this->mapData($item->getKey());
        $replacement = $this->atomicReplacement($item, $expiration['expiresAt']);
        for ($attempt = 0; $attempt < 3; ++$attempt) {
            if ($this->tryAtomicInsert($id, $replacement)) {
                return true;
            }

            $replaced = $this->tryReplaceInvalidAtomic($id, $replacement);
            if ($replaced !== null) {
                return $replaced;
            }
        }

        return false;
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

    /**
     * @return array{ns:string, kind:string, payload:mixed, expires:int|null}
     */
    private function atomicReplacement(CacheItemInterface $item, ?int $expiresAt): array
    {
        return [
            'ns' => $this->ns,
            'kind' => 'data',
            'payload' => $this->binaryValue($this->encodeItem($item, $expiresAt)),
            'expires' => $expiresAt,
        ];
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

    private function isDuplicateKeyFailure(Throwable $failure): bool
    {
        return in_array(AdapterValueNormalizer::intOrZero($failure->getCode()), [11000, 11001, 12582], true)
            || str_contains($failure->getMessage(), 'E11000 duplicate key');
    }

    private function mapData(string $key): string
    {
        return $this->ns . ':d:' . $key;
    }

    private function mapTag(string $tag): string
    {
        return $this->ns . ':m:tag:' . $tag;
    }

    private function matchedCount(mixed $result): int
    {
        if (!is_object($result) || !is_callable([$result, 'getMatchedCount'])) {
            return 0;
        }

        return AdapterValueNormalizer::intOrZero($result->getMatchedCount());
    }

    /** @param array<string, mixed> $row */
    private function recordFromRow(array $row): ?CacheRecord
    {
        $payload = $this->binaryString($row['payload'] ?? null);
        if (!is_string($payload)) {
            return null;
        }
        $record = $this->decodeRecordFromBlob($payload);
        if (!$record instanceof CacheRecord || !$this->recordTagsAreCurrent($record)) {
            return null;
        }

        return $record;
    }

    private function recordTagsAreCurrent(CacheRecord $record): bool
    {
        if ($record->tags === []) {
            return true;
        }

        $current = $this->getTagGenerations(array_keys($record->tags));
        foreach ($record->tags as $tag => $generation) {
            if (($current[$tag] ?? null) !== $generation) {
                return false;
            }
        }

        return true;
    }

    /** @param array{ns:string, kind:string, payload:mixed, expires:int|null} $replacement */
    private function tryAtomicInsert(string $id, array $replacement): bool
    {
        try {
            $this->collection->insertOne(['_id' => $id, ...$replacement]);

            return true;
        } catch (Throwable $failure) {
            if (!$this->isDuplicateKeyFailure($failure)) {
                throw $failure;
            }

            return false;
        }
    }

    /**
     * @param array{ns:string, kind:string, payload:mixed, expires:int|null} $replacement
     * @return bool|null True when replaced, false when a live/non-replaceable value exists, null on a race retry.
     */
    private function tryReplaceInvalidAtomic(string $id, array $replacement): ?bool
    {
        $row = AdapterValueNormalizer::fromJsonOrArrayLike(
            $this->collection->findOne(['_id' => $id]),
        );
        if (!is_array($row)) {
            return null;
        }
        if ($this->recordFromRow($row) instanceof CacheRecord || !array_key_exists('payload', $row)) {
            return false;
        }

        $result = $this->collection->updateOne(
            ['_id' => $id, 'payload' => $row['payload']],
            ['$set' => $replacement],
        );

        return $this->matchedCount($result) > 0 ? true : null;
    }
}
