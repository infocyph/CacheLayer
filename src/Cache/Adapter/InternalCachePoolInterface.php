<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cache\Adapter;

use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Internal contract used by cache items to persist themselves.
 *
 * @internal
 */
interface InternalCachePoolInterface extends CacheItemPoolInterface
{
    public function createItem(string $key): CacheItemInterface;

    /**
     * @param list<string> $tags
     * @return array<string, int>
     */
    public function getTagVersions(array $tags): array;

    /** @param list<string> $tags */
    public function incrementTagVersions(array $tags): bool;

    public function internalPersist(CacheItemInterface $item): bool;

    public function internalQueue(CacheItemInterface $item): bool;

    /**
     * @param list<string> $keys
     * @return array<string, CacheItemInterface>
     */
    public function multiFetch(array $keys): array;

    /**
     * @param array<string, CacheItemInterface> $items
     */
    public function saveItems(array $items): bool;
}
