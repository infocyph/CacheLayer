<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cache\Adapter;

use Infocyph\CacheLayer\Cache\Item\GenericCacheItem;
use Psr\Cache\CacheItemInterface;
use WeakMap;
use WeakReference;

final class WeakMapCacheAdapter extends AbstractCacheAdapter
{
    private readonly string $ns;

    /** @var array<string, string> */
    private array $scalarStore = [];

    /** @var array<string, int|null> */
    private array $weakExpires = [];

    /** @var WeakMap<object, array{key:string,expires:int|null}> */
    private WeakMap $weakObjects;

    /** @var array<string, WeakReference<object>> */
    private array $weakRefs = [];

    public function __construct(string $namespace = 'default')
    {
        $this->ns = sanitize_cache_ns($namespace);
        $this->weakObjects = new WeakMap();
    }

    public function clear(): bool
    {
        $this->scalarStore = [];
        $this->weakRefs = [];
        $this->weakExpires = [];
        $this->weakObjects = new WeakMap();
        $this->deferred = [];

        return true;
    }

    public function count(): int
    {
        $this->pruneCollected();
        $this->pruneExpiredScalar();

        $count = count($this->scalarStore);
        foreach ($this->weakRefs as $mapped => $ref) {
            $obj = $ref->get();
            if (!is_object($obj)) {
                continue;
            }

            if (CachePayloadCodec::isExpired($this->weakExpires[$mapped] ?? null)) {
                continue;
            }

            $count++;
        }

        return $count;
    }

    public function deleteItem(string $key): bool
    {
        $mapped = $this->map($key);
        unset($this->scalarStore[$mapped], $this->weakExpires[$mapped]);

        $ref = $this->weakRefs[$mapped] ?? null;
        if ($ref instanceof WeakReference) {
            $obj = $ref->get();
            if (is_object($obj) && isset($this->weakObjects[$obj])) {
                unset($this->weakObjects[$obj]);
            }
        }

        unset($this->weakRefs[$mapped]);

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
        $this->pruneCollected();
        $mapped = $this->map($key);

        if (isset($this->weakRefs[$mapped])) {
            $ref = $this->weakRefs[$mapped];
            $obj = $ref->get();
            $exp = $this->weakExpires[$mapped] ?? null;

            if (is_object($obj) && !CachePayloadCodec::isExpired($exp)) {
                $item = new GenericCacheItem($this, $key);
                $item->set($obj);
                if ($exp !== null) {
                    $item->expiresAt(CachePayloadCodec::toDateTime($exp));
                }

                return $item;
            }

            $this->deleteItem($key);
        }

        if (!isset($this->scalarStore[$mapped])) {
            return new GenericCacheItem($this, $key);
        }

        return $this->genericFromBlobWithInvalidator(
            $key,
            $this->scalarStore[$mapped],
            function () use ($mapped): bool {
                unset($this->scalarStore[$mapped]);

                return true;
            },
        );
    }

    public function hasItem(string $key): bool
    {
        return $this->getItem($key)->isHit();
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
            $mapped = $this->map($saveItem->getKey());
            $value = $saveItem->get();

            if (is_object($value)) {
                $ref = WeakReference::create($value);
                $this->weakRefs[$mapped] = $ref;
                $this->weakExpires[$mapped] = $expires['expiresAt'];
                $this->weakObjects[$value] = ['key' => $mapped, 'expires' => $expires['expiresAt']];
                unset($this->scalarStore[$mapped]);

                return true;
            }

            unset($this->weakRefs[$mapped], $this->weakExpires[$mapped]);
            $this->scalarStore[$mapped] = CachePayloadCodec::encode($value, $expires['expiresAt']);

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

    private function pruneCollected(): void
    {
        foreach ($this->weakRefs as $mapped => $ref) {
            $obj = $ref->get();
            if (!is_object($obj) || CachePayloadCodec::isExpired($this->weakExpires[$mapped] ?? null)) {
                unset($this->weakRefs[$mapped], $this->weakExpires[$mapped]);
            }
        }
    }

    private function pruneExpiredScalar(): void
    {
        foreach ($this->scalarStore as $mapped => $blob) {
            $record = CachePayloadCodec::decode($blob);
            if ($record === null || CachePayloadCodec::isExpired($record['expires'])) {
                unset($this->scalarStore[$mapped]);
            }
        }
    }
}
