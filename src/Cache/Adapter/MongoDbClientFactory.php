<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cache\Adapter;

use Infocyph\CacheLayer\Exceptions\CacheInvalidArgumentException;
use MongoDB\Client;
use Throwable;

/** @internal */
final class MongoDbClientFactory
{
    public static function create(#[\SensitiveParameter] string $uri): object
    {
        try {
            return new Client($uri);
        } catch (Throwable) {
            throw new CacheInvalidArgumentException('Unable to create MongoDB client from the configured URI.');
        }
    }
}
