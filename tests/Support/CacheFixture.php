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

    /**
     * @param array<mixed> $backend
     * @return array{string, string, string}
     * @throws UnexpectedValueException
     */
    public static function pdoCredentials(array $backend): array
    {
        if (count($backend) !== 3 || !isset($backend[0], $backend[1], $backend[2])
            || !is_string($backend[0]) || !is_string($backend[1]) || !is_string($backend[2])) {
            throw new UnexpectedValueException('Invalid PDO fixture credentials.');
        }

        return [$backend[0], $backend[1], $backend[2]];
    }

    public static function environment(string $name): ?string
    {
        $value = getenv($name);

        return $value === false || $value === '' || $value === '0' ? null : $value;
    }

    /**
     * @throws UnexpectedValueException
     * @throws \Infocyph\CacheLayer\Exceptions\CacheBackendException
     * @throws \Infocyph\CacheLayer\Exceptions\CacheInvalidArgumentException
     */
    public static function item(Cache $cache, string $key): CacheItem
    {
        $item = $cache->getItem($key);
        if (!$item instanceof CacheItem) {
            throw new UnexpectedValueException('The test fixture requires a native CacheLayer item.');
        }

        return $item;
    }
}
