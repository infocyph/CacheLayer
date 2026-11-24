<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Tests\Support;

use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\CacheLayer\Cache\Item\CacheItem;
use PDO;
use UnexpectedValueException;

final class CacheFixture
{
    /** @throws UnexpectedValueException */
    public static function countRows(PDO $connection, string $sql): int
    {
        $statement = $connection->query($sql);
        if ($statement === false) {
            throw new UnexpectedValueException('Unable to query fixture row count.');
        }
        $count = $statement->fetchColumn();
        if (is_int($count) && $count >= 0) {
            return $count;
        }
        if (is_string($count) && ctype_digit($count) && filter_var($count, FILTER_VALIDATE_INT) !== false) {
            return (int) $count;
        }

        throw new UnexpectedValueException('Invalid fixture row count.');
    }

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
