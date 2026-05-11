<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cache\Tiering;

use Infocyph\CacheLayer\Cache\Adapter;
use Infocyph\CacheLayer\Exceptions\CacheInvalidArgumentException;
use Psr\Cache\CacheItemPoolInterface;

final class TieredPoolFactory
{
    /**
     * @param array<int, mixed> $tiers
     * @return array<int, CacheItemPoolInterface>
     */
    public static function fromArray(array $tiers): array
    {
        if ($tiers === []) {
            throw new CacheInvalidArgumentException('Cache::tiered() requires at least one tier.');
        }

        $pools = [];
        foreach ($tiers as $index => $tier) {
            $pools[] = self::resolvePool($tier, $index);
        }

        return $pools;
    }

    /**
     * @param array<string, mixed> $descriptor
     */
    private static function bool(array $descriptor, string $key, bool $default): bool
    {
        if (!array_key_exists($key, $descriptor)) {
            return $default;
        }

        $value = $descriptor[$key];
        if (!is_bool($value)) {
            throw new CacheInvalidArgumentException(
                sprintf('Tier descriptor key `%s` must be bool, got %s.', $key, get_debug_type($value)),
            );
        }

        return $value;
    }

    private static function buildScyllaSession(string $keyspace): object
    {
        if (!class_exists(\Cassandra::class)) {
            throw new CacheInvalidArgumentException(
                'ext-cassandra is required unless a ScyllaDB/Cassandra session is provided.',
            );
        }

        /** @var object */
        return \Cassandra::cluster()->build()->connect($keyspace);
    }

    /**
     * @param array<string, mixed> $descriptor
     */
    private static function descriptorToPool(array $descriptor, int|string $index): CacheItemPoolInterface
    {
        $driverValue = $descriptor['driver'] ?? $descriptor['type'] ?? null;
        if (!is_string($driverValue) || $driverValue === '') {
            throw new CacheInvalidArgumentException(
                sprintf("Tier descriptor at index '%s' requires string key `driver`.", (string) $index),
            );
        }
        $driver = strtolower($driverValue);

        $namespace = isset($descriptor['namespace']) && is_string($descriptor['namespace'])
            ? $descriptor['namespace']
            : 'default';
        $client = $descriptor['client'] ?? $descriptor['session'] ?? null;

        return match ($driver) {
            'apcu' => new Adapter\ApcuCacheAdapter($namespace),
            'array', 'memory' => new Adapter\ArrayCacheAdapter($namespace),
            'file' => new Adapter\FileCacheAdapter($namespace, self::nullableString($descriptor, 'dir', 'base_dir')),
            'php_files' => new Adapter\PhpFilesCacheAdapter($namespace, self::nullableString($descriptor, 'dir', 'base_dir')),
            'memcache', 'memcached' => new Adapter\MemCacheAdapter(
                $namespace,
                self::servers($descriptor['servers'] ?? null),
                self::memcachedClient($client, $index),
            ),
            'redis' => new Adapter\RedisCacheAdapter(
                $namespace,
                self::string($descriptor, 'dsn', 'redis://127.0.0.1:6379'),
                self::redisClient($client, $index, 'redis'),
            ),
            'valkey' => new Adapter\ValkeyCacheAdapter(
                $namespace,
                self::string($descriptor, 'dsn', 'valkey://127.0.0.1:6379'),
                self::redisClient($client, $index, 'valkey'),
            ),
            'redis_cluster' => new Adapter\RedisClusterCacheAdapter(
                $namespace,
                self::seeds($descriptor['seeds'] ?? null),
                self::float($descriptor, 'timeout', 1.0),
                self::float($descriptor, 'read_timeout', 1.0),
                self::bool($descriptor, 'persistent', false),
                is_object($client) ? $client : null,
            ),
            'pdo' => new Adapter\PdoCacheAdapter(
                $namespace,
                self::nullableString($descriptor, 'dsn'),
                self::nullableString($descriptor, 'username'),
                self::nullableString($descriptor, 'password'),
                self::pdoClient($client, $index),
                self::string($descriptor, 'table', 'cachelayer_entries'),
            ),
            'sqlite' => new Adapter\PdoCacheAdapter(
                $namespace,
                self::sqliteDsn($descriptor),
                null,
                null,
                null,
                self::string($descriptor, 'table', 'cachelayer_entries'),
            ),
            'mongodb' => self::mongoPool($descriptor, $namespace, $client, $index),
            'scylladb', 'scylla' => new Adapter\ScyllaDbCacheAdapter(
                is_object($client) ? $client : self::buildScyllaSession(self::string($descriptor, 'keyspace', 'cachelayer')),
                self::string($descriptor, 'keyspace', 'cachelayer'),
                self::string($descriptor, 'table', 'cachelayer_entries'),
                $namespace,
            ),
            'shared_memory' => new Adapter\SharedMemoryCacheAdapter(
                $namespace,
                self::int($descriptor, 'segment_size', 16_777_216),
            ),
            'weak_map' => new Adapter\WeakMapCacheAdapter($namespace),
            'null', 'null_store' => new Adapter\NullCacheAdapter(),
            default => throw new CacheInvalidArgumentException(
                sprintf("Unsupported tier driver '%s' at index '%s'.", $driver, (string) $index),
            ),
        };
    }

    /**
     * @param array<string, mixed> $descriptor
     */
    private static function float(array $descriptor, string $key, float $default): float
    {
        if (!array_key_exists($key, $descriptor)) {
            return $default;
        }

        $value = $descriptor[$key];
        if (!is_int($value) && !is_float($value)) {
            throw new CacheInvalidArgumentException(
                sprintf('Tier descriptor key `%s` must be float, got %s.', $key, get_debug_type($value)),
            );
        }

        return (float) $value;
    }

    /**
     * @param array<string, mixed> $descriptor
     */
    private static function int(array $descriptor, string $key, int $default): int
    {
        if (!array_key_exists($key, $descriptor)) {
            return $default;
        }

        $value = $descriptor[$key];
        if (!is_int($value)) {
            throw new CacheInvalidArgumentException(
                sprintf('Tier descriptor key `%s` must be int, got %s.', $key, get_debug_type($value)),
            );
        }

        return $value;
    }

    private static function memcachedClient(mixed $client, int|string $index): ?\Memcached
    {
        if ($client === null) {
            return null;
        }

        if (!$client instanceof \Memcached) {
            throw new CacheInvalidArgumentException(
                sprintf("Memcached tier at index '%s' requires `client` instance of Memcached.", (string) $index),
            );
        }

        return $client;
    }

    /**
     * @param array<string, mixed> $descriptor
     */
    private static function mongoPool(array $descriptor, string $namespace, mixed $client, int|string $index): CacheItemPoolInterface
    {
        $collection = $descriptor['collection'] ?? null;
        if (is_object($collection)) {
            return new Adapter\MongoDbCacheAdapter($collection, $namespace);
        }

        if (!is_object($client)) {
            throw new CacheInvalidArgumentException(
                sprintf("MongoDB tier at index '%s' requires object `collection` or `client`.", (string) $index),
            );
        }

        return Adapter\MongoDbCacheAdapter::fromClient(
            $client,
            self::string($descriptor, 'database', 'cachelayer'),
            self::string($descriptor, 'collection_name', 'entries'),
            $namespace,
        );
    }

    /**
     * @param array<mixed, mixed> $tier
     * @return array<string, mixed>
     */
    private static function normalizeDescriptor(array $tier): array
    {
        $descriptor = [];
        foreach ($tier as $key => $value) {
            if (!is_string($key)) {
                throw new CacheInvalidArgumentException(
                    sprintf(
                        'Tier descriptor keys must be strings; got key type %s.',
                        get_debug_type($key),
                    ),
                );
            }

            $descriptor[$key] = $value;
        }

        return $descriptor;
    }

    /**
     * @param array<string, mixed> $descriptor
     */
    private static function nullableString(array $descriptor, string ...$keys): ?string
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $descriptor)) {
                continue;
            }

            $value = $descriptor[$key];
            if ($value === null) {
                return null;
            }

            if (!is_string($value)) {
                throw new CacheInvalidArgumentException(
                    sprintf('Tier descriptor key `%s` must be string|null, got %s.', $key, get_debug_type($value)),
                );
            }

            return $value;
        }

        return null;
    }

    private static function pdoClient(mixed $client, int|string $index): ?\PDO
    {
        if ($client === null) {
            return null;
        }

        if (!$client instanceof \PDO) {
            throw new CacheInvalidArgumentException(
                sprintf("PDO tier at index '%s' requires `client` instance of PDO.", (string) $index),
            );
        }

        return $client;
    }

    private static function redisClient(mixed $client, int|string $index, string $driver): ?\Redis
    {
        if ($client === null) {
            return null;
        }

        if (!$client instanceof \Redis) {
            throw new CacheInvalidArgumentException(
                sprintf(
                    "%s tier at index '%s' requires `client` instance of Redis.",
                    ucfirst($driver),
                    (string) $index,
                ),
            );
        }

        return $client;
    }

    private static function resolvePool(mixed $tier, int|string $index): CacheItemPoolInterface
    {
        if ($tier instanceof CacheItemPoolInterface) {
            return $tier;
        }

        if (!is_array($tier)) {
            throw new CacheInvalidArgumentException(
                sprintf(
                    "Invalid tier at index '%s': expected CacheItemPoolInterface or descriptor array, got %s.",
                    (string) $index,
                    get_debug_type($tier),
                ),
            );
        }

        return self::descriptorToPool(self::normalizeDescriptor($tier), $index);
    }

    /**
     * @return array<int, string>
     */
    private static function seeds(mixed $value): array
    {
        if ($value === null) {
            return ['127.0.0.1:6379'];
        }

        if (!is_array($value)) {
            throw new CacheInvalidArgumentException(
                sprintf('Tier descriptor key `seeds` must be array<int, string>, got %s.', get_debug_type($value)),
            );
        }

        $seeds = [];
        foreach ($value as $seed) {
            if (!is_string($seed)) {
                throw new CacheInvalidArgumentException(
                    sprintf(
                        'Tier descriptor key `seeds` must contain only strings, got %s.',
                        get_debug_type($seed),
                    ),
                );
            }
            $seeds[] = $seed;
        }

        return $seeds;
    }

    /**
     * @return array<int, array{0:string,1:int,2:int}>
     */
    private static function servers(mixed $value): array
    {
        if ($value === null) {
            return [['127.0.0.1', 11211, 0]];
        }

        if (!is_array($value)) {
            throw new CacheInvalidArgumentException(
                sprintf(
                    'Tier descriptor key `servers` must be array<int, array{host, port, weight}>, got %s.',
                    get_debug_type($value),
                ),
            );
        }

        $servers = [];
        foreach ($value as $server) {
            if (!is_array($server) || !is_string($server[0] ?? null) || !is_int($server[1] ?? null)) {
                throw new CacheInvalidArgumentException(
                    'Tier descriptor key `servers` entries must be [string host, int port, int weight].',
                );
            }

            $weight = $server[2] ?? 0;
            if (!is_int($weight)) {
                throw new CacheInvalidArgumentException(
                    'Tier descriptor key `servers` entries weight must be int.',
                );
            }

            $servers[] = [$server[0], $server[1], $weight];
        }

        return $servers;
    }

    /**
     * @param array<string, mixed> $descriptor
     */
    private static function sqliteDsn(array $descriptor): ?string
    {
        $file = self::nullableString($descriptor, 'file');

        return $file === null ? null : 'sqlite:' . $file;
    }

    /**
     * @param array<string, mixed> $descriptor
     */
    private static function string(array $descriptor, string $key, string $default): string
    {
        if (!array_key_exists($key, $descriptor)) {
            return $default;
        }

        $value = $descriptor[$key];
        if (!is_string($value)) {
            throw new CacheInvalidArgumentException(
                sprintf('Tier descriptor key `%s` must be string, got %s.', $key, get_debug_type($value)),
            );
        }

        return $value;
    }
}
