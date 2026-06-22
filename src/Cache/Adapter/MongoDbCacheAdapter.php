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
        $count = $this->collection->countDocuments([
            'ns' => $this->ns,
            '$or' => [
                ['expires' => null],
                ['expires' => ['$gt' => time()]],
            ],
        ]);

        return is_numeric($count) ? max(0, (int) $count) : 0;
    }

    public function deleteItem(string $key): bool
    {
        $this->collection->deleteOne(['_id' => $this->map($key)]);

        return true;
    }

    /**
     * @param array $keys The keys argument.
     * @phpstan-param list<string> $keys
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
        $doc = $this->collection->findOne(['_id' => $this->map($key)]);
        $row = AdapterValueNormalizer::fromJsonOrArrayLike($doc);

        if ($row === null) {
            return $this->genericMiss($key);
        }

        $payload = $row['payload'] ?? null;

        return $this->genericFromBase64($key, is_string($payload) ? $payload : null);
    }

    public function hasItem(string $key): bool
    {
        $count = $this->collection->countDocuments([
            '_id' => $this->map($key),
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
     * @phpstan-return array<string, GenericCacheItem>
     */
    public function multiFetch(array $keys): array
    {
        return $this->multiFetchItems($keys, $this->getItem(...));
    }

    public function save(CacheItemInterface $item): bool
    {
        return $this->saveEncoded($item, function (CacheItemInterface $saveItem, array $expires): bool {
            $this->collection->updateOne(
                ['_id' => $this->map($saveItem->getKey())],
                [
                    '$set' => [
                        'ns' => $this->ns,
                        'payload' => base64_encode(CachePayloadCodec::encode($saveItem->get(), $expires['expiresAt'])),
                        'expires' => $expires['expiresAt'],
                    ],
                ],
                ['upsert' => true],
            );

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
