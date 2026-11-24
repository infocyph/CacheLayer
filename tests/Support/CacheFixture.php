<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Tests\Support;

use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\CacheLayer\Cache\Item\CacheItem;
use UnexpectedValueException;

final class CacheFixture
{
    /** @throws UnexpectedValueException */
    public static function item(Cache $cache, string $key): CacheItem
    {
        $item = $cache->getItem($key);
        if (!$item instanceof CacheItem) {
            throw new UnexpectedValueException('The test fixture requires a native CacheLayer item.');
        }

        return $item;
    }
}
