<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Support;

/** @internal */
final class RedisValueGuard
{
    private const string DELETE_IF_UNCHANGED_SCRIPT = <<<'LUA'
local current = redis.call('GET', KEYS[1])
if current ~= ARGV[1] then
    return 0
end
return redis.call('DEL', KEYS[1])
LUA;

    private const string REPLACE_IF_UNCHANGED_SCRIPT = <<<'LUA'
local current = redis.call('GET', KEYS[1])
if not current then
    return false
end
if current == ARGV[1] then
    redis.call('SET', KEYS[1], ARGV[2])
    return ARGV[2]
end
return current
LUA;

    public static function deleteIfUnchanged(\Redis $client, string $key, string $observed): bool
    {
        return $client->eval(self::DELETE_IF_UNCHANGED_SCRIPT, [$key, $observed], 1) === 1;
    }

    public static function replaceIfUnchanged(
        \Redis $client,
        string $key,
        string $observed,
        string $replacement,
    ): string|false {
        $result = $client->eval(
            self::REPLACE_IF_UNCHANGED_SCRIPT,
            [$key, $observed, $replacement],
            1,
        );

        return is_string($result) ? $result : false;
    }
}
