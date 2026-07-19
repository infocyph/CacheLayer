<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cache;

use BadMethodCallException;
use Closure;
use Countable;
use DateInterval;
use DateTime;
use Infocyph\CacheLayer\Cache\Item\AbstractCacheItem;
use Infocyph\CacheLayer\Cache\Lock\FileLockProvider;
use Infocyph\CacheLayer\Cache\Lock\LockProviderInterface;
use Infocyph\CacheLayer\Cache\Lock\MemcachedLockProvider;
use Infocyph\CacheLayer\Cache\Lock\PdoLockProvider;
use Infocyph\CacheLayer\Cache\Lock\RedisLockProvider;
use Infocyph\CacheLayer\Cache\Metrics\CacheMetricsCollectorInterface;
use Infocyph\CacheLayer\Cache\Metrics\InMemoryCacheMetricsCollector;
use Infocyph\CacheLayer\Cache\Tiering\TieredPoolFactory;
use Infocyph\CacheLayer\Exceptions\CacheInvalidArgumentException;
use Infocyph\CacheLayer\Serializer\ValueSerializer;
use MongoDB\Client;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException as Psr6InvalidArgumentException;
use Psr\SimpleCache\InvalidArgumentException as SimpleCacheInvalidArgument;

final class Cache implements CacheInterface
{
    use CacheReadRememberTrait {
        get as private traitGet;
        getItem as private traitGetItem;
        getItems as private traitGetItems;
        hasItem as private traitHasItem;
        remember as private traitRemember;
    }

    private const int STAMPEDE_JITTER_PERCENT = 8;

    private const float STAMPEDE_LOCK_WAIT_SECONDS = 5.0;

    private const string TAG_META_PREFIX = '__im_tagm_';

    private const string TAG_VERSION_PREFIX = '__im_tagv_';

    private ?Closure $metricsExportHook = null;

    /**
     * Cache constructor.
     *
     * @param CacheItemPoolInterface $adapter Any PSR-6 cache pool.
     * @param LockProviderInterface $lockProvider The lock provider argument.
     * @param CacheMetricsCollectorInterface $metrics The metrics argument.
     */
    public function __construct(
        private readonly CacheItemPoolInterface $adapter,
        private LockProviderInterface $lockProvider = new FileLockProvider(),
        private CacheMetricsCollectorInterface $metrics = new InMemoryCacheMetricsCollector(),
    ) {}

    /**
     * Retrieves a value from the cache using magic property access.
     *
     * This method allows accessing cached values using property syntax.
     * It is equivalent to calling the `get()` method with the property name.
     *
     *
     * @throws SimpleCacheInvalidArgument|Psr6InvalidArgumentException if the key is invalid.
     * @param string $name The key for which to retrieve the value.
     * @phpstan-return mixed The value associated with the given key.
     */
    public function __get(string $name): mixed
    {
        return $this->get($name);
    }

    /**
     * Whether the given key is set in the cache.
     *
     * @throws Psr6InvalidArgumentException
 * @param string $name The name argument.
     */
    public function __isset(string $name): bool
    {
        return $this->has($name);
    }

    /**
     * Sets a value in the cache.
     *
     * Magic property setter, equivalent to calling `set($name, $value, null)`.
     *
     *
     * @throws SimpleCacheInvalidArgument if the key is invalid
 * @param string $name The name argument.
 * @param mixed $value The value argument.
     */
    public function __set(string $name, mixed $value): void
    {
        $this->set($name, $value);
    }

    /**
     * Magic method to unset an item in the cache.
     *
     * This method deletes the cache entry associated with the given name.
     *
     *
     * @throws SimpleCacheInvalidArgument
     * @param string $name The name of the cache item to unset.
     */
    public function __unset(string $name): void
    {
        $this->delete($name);
    }

    /**
     * Static factory for APCu-based cache.
     *
     * @param string $namespace Cache prefix. Will be suffixed to each key.
     */
    public static function apcu(string $namespace = 'default'): self
    {
        return new self(new Adapter\ApcuCacheAdapter($namespace));
    }

    /**
     * @param array $pools The pools argument.
     * @phpstan-param array<int, CacheItemPoolInterface> $pools
     */
    public static function chain(array $pools): self
    {
        return new self(new Adapter\ChainCacheAdapter($pools));
    }

    /**
     * Static factory for file-based cache.
     *
     * @param string $namespace Cache prefix. Will be suffixed to each key.
     * @param string|null $dir Directory to store cache files (or null → sys temp dir).
     */
    public static function file(string $namespace = 'default', ?string $dir = null): self
    {
        return new self(new Adapter\FileCacheAdapter($namespace, $dir));
    }

    /**
     * Static factory for local cache selection.
     *
     * Determines the appropriate caching mechanism based on the availability of the APCu extension.
     * If APCu is enabled, it returns an APCu-based cache; otherwise, it defaults to a file-based cache.
     *
     * @param string $namespace Cache prefix. Will be suffixed to each key.
     * @param string|null $dir Directory to store cache files (or null → sys temp dir), used if APCu is not enabled.
     * @phpstan-return static An instance of the cache using the selected adapter.
     */
    public static function local(
        string $namespace = 'default',
        ?string $dir = null,
    ): self {
        if (extension_loaded('apcu') && apcu_enabled()) {
            return self::apcu($namespace);
        }

        return self::file($namespace, $dir);
    }

    /**
     * Static factory for Memcached-based cache.
     *
     *                                                         The `weight` is a float between 0 and 1, and defaults to 0.
     * @param string $namespace Cache prefix. Will be suffixed to each key.
     * @param array $servers Memcached servers as an array of `[host, port, weight]`.
     * @phpstan-param array<int, array{0:string,1:int,2:int}> $servers Memcached servers as an array of `[host, port, weight]`.
     * @param \Memcached|null $client Optional preconfigured Memcached instance.
     */
    public static function memcache(
        string $namespace = 'default',
        array $servers = [['127.0.0.1', 11211, 0]],
        ?\Memcached $client = null,
    ): self {
        $adapter = new Adapter\MemCacheAdapter($namespace, $servers, $client);

        return (new self($adapter))->setLockProvider(
            new MemcachedLockProvider($adapter->getClient()),
        );
    }

    public static function memory(string $namespace = 'default'): self
    {
        return new self(new Adapter\ArrayCacheAdapter($namespace));
    }

    public static function mongodb(
        string $namespace = 'default',
        ?object $collection = null,
        ?object $client = null,
        string $database = 'cachelayer',
        string $collectionName = 'entries',
        string $uri = 'mongodb://127.0.0.1:27017',
    ): self {
        if ($collection === null) {
            if ($client === null) {
                if (!class_exists(Client::class)) {
                    throw new CacheInvalidArgumentException(
                        'mongodb/mongodb is required unless a collection/client is provided.',
                    );
                }

                $client = new Client($uri);
            }

            $adapter = Adapter\MongoDbCacheAdapter::fromClient(
                $client,
                $database,
                $collectionName,
                $namespace,
            );

            return new self($adapter);
        }

        return new self(new Adapter\MongoDbCacheAdapter($collection, $namespace));
    }

    public static function nullStore(): self
    {
        return new self(new Adapter\NullCacheAdapter());
    }

    public static function pdo(
        string $namespace = 'default',
        ?string $dsn = null,
        ?string $username = null,
        ?string $password = null,
        ?\PDO $pdo = null,
        string $table = 'cachelayer_entries',
    ): self {
        $adapter = new Adapter\PdoCacheAdapter($namespace, $dsn, $username, $password, $pdo, $table);
        $lockProvider = new FileLockProvider();
        $pdoLockProviderClass = PdoLockProvider::class;
        if (class_exists($pdoLockProviderClass)) {
            $lockProvider = new $pdoLockProviderClass($adapter->getClient());
        }

        return (new self($adapter))->setLockProvider(
            $lockProvider,
        );
    }

    public static function phpFiles(string $namespace = 'default', ?string $dir = null): self
    {
        return new self(new Adapter\PhpFilesCacheAdapter($namespace, $dir));
    }

    /**
     * Static factory for Redis cache.
     *
     *                    or null to use the default ('redis://127.0.0.1:6379').
     * @param string $namespace Cache prefix.
     * @param string $dsn DSN for Redis connection (e.g. 'redis://127.0.0.1:6379'),
     * @param \Redis|null $client Optional preconfigured Redis instance.
     */
    public static function redis(
        string $namespace = 'default',
        string $dsn = 'redis://127.0.0.1:6379',
        ?\Redis $client = null,
    ): self {
        $adapter = new Adapter\RedisCacheAdapter($namespace, $dsn, $client);

        return (new self($adapter))->setLockProvider(
            new RedisLockProvider($adapter->getClient()),
        );
    }

    /**
     * @param string $namespace The namespace argument.
     * @param array $seeds The seeds argument.
     * @param float $timeout The timeout argument.
     * @param float $readTimeout The read timeout argument.
     * @param bool $persistent The persistent argument.
     * @param object|null $client The client argument.
     * @phpstan-param array<int, string> $seeds
     */
    public static function redisCluster(
        string $namespace = 'default',
        array $seeds = ['127.0.0.1:6379'],
        float $timeout = 1.0,
        float $readTimeout = 1.0,
        bool $persistent = false,
        ?object $client = null,
    ): self {
        return new self(
            new Adapter\RedisClusterCacheAdapter(
                $namespace,
                $seeds,
                $timeout,
                $readTimeout,
                $persistent,
                $client,
            ),
        );
    }

    public static function scyllaDb(
        string $namespace = 'default',
        ?object $session = null,
        string $keyspace = 'cachelayer',
        string $table = 'cachelayer_entries',
    ): self {
        if ($session === null) {
            if (!class_exists(\Cassandra::class)) {
                throw new CacheInvalidArgumentException(
                    'ext-cassandra is required unless a ScyllaDB/Cassandra session is provided.',
                );
            }

            /** @var object $session */
            $session = \Cassandra::cluster()->build()->connect($keyspace);
        }

        return new self(new Adapter\ScyllaDbCacheAdapter($session, $keyspace, $table, $namespace));
    }

    public static function sharedMemory(string $namespace = 'default', int $segmentSize = 16_777_216): self
    {
        return new self(new Adapter\SharedMemoryCacheAdapter($namespace, $segmentSize));
    }

    /**
     * Static factory for SQLite-based cache.
     *
     * @param string $namespace Cache prefix. Will be suffixed to each key.
     * @param string|null $file Path to SQLite file (or null → cachelayer temp subdirectory).
     */
    public static function sqlite(string $namespace = 'default', ?string $file = null): self
    {
        $dbPath = $file ?? Adapter\PdoCacheAdapter::defaultSqliteFileForNamespace($namespace);

        return self::pdo(
            namespace: $namespace,
            dsn: 'sqlite:' . $dbPath,
        );
    }

    /**
     * Builds a tiered cache from pool instances and/or descriptor arrays.
     *
     * @param array $tiers The tiers argument.
     * @param bool $writeToL1 The write to l1 argument.
     * @phpstan-param array<int, CacheItemPoolInterface|array<string, mixed>> $tiers
     */
    public static function tiered(array $tiers, bool $writeToL1 = true): self
    {
        $pools = TieredPoolFactory::fromArray($tiers);

        return new self(new Adapter\ChainCacheAdapter($pools, $writeToL1));
    }

    /**
     * Static factory for Valkey cache.
     *
     * @param string $namespace Cache prefix.
     * @param string $dsn DSN for Valkey connection (e.g. 'valkey://127.0.0.1:6379').
     * @param \Redis|null $client Optional preconfigured Redis-compatible instance.
     */
    public static function valkey(
        string $namespace = 'default',
        string $dsn = 'valkey://127.0.0.1:6379',
        ?\Redis $client = null,
    ): self {
        $adapter = new Adapter\ValkeyCacheAdapter($namespace, $dsn, $client);

        return (new self($adapter))->setLockProvider(
            new RedisLockProvider($adapter->getClient()),
        );
    }

    public static function weakMap(string $namespace = 'default'): self
    {
        return new self(new Adapter\WeakMapCacheAdapter($namespace));
    }

    /**
     * Removes all items from the cache.
     *
     *              True if the operation was successful, false otherwise.
     */
    public function clear(): bool
    {
        return $this->adapter->clear();
    }

    /**
     * Wipes out the entire cache.
     */
    public function clearCache(): bool
    {
        return $this->clear();
    }

    /**
     * Commits any deferred cache items.
     *
     * If the underlying adapter supports deferred cache items, this
     * method will persist all items that have been added to the deferred
     * queue. If the adapter does not support deferred cache items, this
     * method is a no-op.
     *
     * @phpstan-return bool True if all deferred items were successfully saved, false otherwise.
     */
    public function commit(): bool
    {
        return $this->adapter->commit();
    }

    public function configurePayloadCompression(?int $thresholdBytes = null, int $level = 6): self
    {
        Adapter\CachePayloadCodec::configureCompression($thresholdBytes, $level);

        return $this;
    }

    public function configurePayloadSecurity(?string $integrityKey = null, ?int $maxPayloadBytes = 8_388_608): self
    {
        Adapter\CachePayloadCodec::configureSecurity($integrityKey, $maxPayloadBytes);

        return $this;
    }

    public function configureSerializationSecurity(
        bool $allowClosurePayloads = true,
        bool $allowObjectPayloads = true,
    ): self {
        ValueSerializer::configureSecurity(
            allowClosurePayloads: $allowClosurePayloads,
            allowObjectPayloads: $allowObjectPayloads,
        );

        return $this;
    }

    /**
     * Returns the number of items in the cache.
     *
     * If the adapter implements the {@see Countable} interface, it will be
     * used to retrieve the count. Otherwise, this method will use the
     * {@see iterable} interface to count the items.
     *
     * @throws Psr6InvalidArgumentException
     */
    public function count(): int
    {
        return $this->adapter instanceof Countable
            ? count($this->adapter)
            : iterator_count($this->adapter->getItems([]));
    }

    /**
     * Delete an item from the cache.
     *
     * @throws SimpleCacheInvalidArgument if the key is invalid
 * @param string $key The key argument.
     */
    public function delete(string $key): bool
    {
        $this->validateKey($key);

        try {
            $deleted = $this->adapter->deleteItem($key);
        } catch (Psr6InvalidArgumentException $e) {
            throw new CacheInvalidArgumentException($e->getMessage(), 0, $e);
        }

        $this->clearTagMeta($key);
        $this->metric('delete');

        return $deleted;
    }

    /**
     * Deletes a single item from the cache.
     *
     * This method deletes the item from the cache if it exists. If the item does
     * not exist, it is silently ignored.
     *
     *                    The key of the item to delete.
     *              True if the item was successfully deleted, false otherwise.
     *
     * @throws Psr6InvalidArgumentException
 * @param string $key The key argument.
     */
    public function deleteItem(string $key): bool
    {
        $this->validateKey($key);
        $deleted = $this->adapter->deleteItem($key);
        $this->clearTagMeta($key);
        $this->metric('delete');

        return $deleted;
    }

    /**
     * Deletes multiple items from the cache.
     *
     *
     * @throws Psr6InvalidArgumentException
     * @param array $keys The array of keys to delete.
     * @phpstan-param string[] $keys The array of keys to delete.
     * @phpstan-return bool True if all items were successfully deleted, false otherwise.
     */
    public function deleteItems(array $keys): bool
    {
        foreach ($keys as $k) {
            $this->validateKey((string) $k);
        }
        $deleted = $this->adapter->deleteItems($keys);
        foreach ($keys as $key) {
            $this->clearTagMeta((string) $key);
        }
        $this->metric('delete_batch');

        return $deleted;
    }

    /**
     * Deletes multiple keys from the cache.
     *
     *
     * @throws SimpleCacheInvalidArgument if any key is invalid
     * @param iterable $keys The keys argument.
     * @phpstan-param iterable<int|string, string> $keys
     */
    public function deleteMultiple(iterable $keys): bool
    {
        $allSucceeded = true;
        foreach ($keys as $k) {
            /** @var string $k */
            $this->validateKey($k);
            if (!$this->deleteItem($k)) {
                $allSucceeded = false;
            }
        }

        return $allSucceeded;
    }

    /**
     * Returns metrics grouped by readable adapter name.
     *
     * @phpstan-return array<string, array<string, int>>
     */
    public function exportMetrics(): array
    {
        $snapshot = $this->readableMetricsSnapshot($this->metrics->export());
        if ($this->metricsExportHook !== null) {
            ($this->metricsExportHook)($snapshot);
        }

        return $snapshot;
    }

    /**
     * Fetches a value from the cache. If the key does not exist, returns $default.
     *
     * @throws SimpleCacheInvalidArgument|Psr6InvalidArgumentException if the key is invalid
 * @param string $key The key argument.
 * @param mixed $default The default argument.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->traitGet($key, $default);
    }

    /**
     * Retrieves a Cache Item representing the specified key.
     *
     * This method returns a CacheItemInterface object containing the cached value.
     *
     *                    The key of the item to retrieve.
     *                            The retrieved Cache Item.
     *
     * @throws CacheInvalidArgumentException
     *                                       If the $key is invalid or if a CacheLoader is not available when
     *                                       the value is not found.
 * @param string $key The key argument.
     */
    public function getItem(string $key): CacheItemInterface
    {
        return $this->traitGetItem($key);
    }

    /**
     * Returns an iterable of {@see CacheItemInterface} objects for the given
     * keys.
     *
     * If no keys are provided, an empty iterator is returned.
     *
     * If the adapter supports it, the method will use the adapter's
     * `multiFetch` method. Otherwise, it iterates over the keys and calls
     * `getItem` on each key.
     *
     *                       An array of keys to fetch from the cache.
     *                                      An iterable of CacheItemInterface objects.
     * @param array $keys The keys argument.
     * @phpstan-param string[] $keys
     * @phpstan-return iterable<CacheItemInterface>
     */
    public function getItems(array $keys = []): iterable
    {
        return $this->traitGetItems($keys);
    }

    /**
     * Returns an iterable of {@see CacheItemInterface} objects for the given
     * keys.
     *
     * If no keys are provided, an empty iterator is returned.
     *
     * This method is a wrapper for `getItems()`, and is intended for use with
     * iterators.
     *
     *                       An array of keys to fetch from the cache.
     *                                      An iterable of CacheItemInterface objects.
     * @param array $keys The keys argument.
     * @phpstan-param string[] $keys
     * @phpstan-return iterable<CacheItemInterface>
     */
    public function getItemsIterator(array $keys = []): iterable
    {
        return $this->getItems($keys);
    }

    /**
     * Obtains multiple values by their keys.
     *
     *
     * @throws SimpleCacheInvalidArgument|Psr6InvalidArgumentException if any key is invalid
     * @param iterable $keys The keys argument.
     * @param mixed $default The default argument.
     * @phpstan-param iterable<int|string, string> $keys
     * @phpstan-return iterable<string, mixed>
     */
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $result = [];
        foreach ($keys as $k) {
            /** @var string $k */
            $this->validateKey($k);
            $result[$k] = $this->get($k, $default);
        }

        return $result;
    }

    /**
     * Determines whether an item exists in the cache.
     *
     * @throws Psr6InvalidArgumentException if the key is invalid
 * @param string $key The key argument.
     */
    public function has(string $key): bool
    {
        return $this->hasItem($key);
    }

    /**
     * Checks if an item is present in the cache.
     *
     *                    The key to check.
     *              True if the item exists in the cache, false otherwise.
     *
     * @throws Psr6InvalidArgumentException
 * @param string $key The key argument.
     */
    public function hasItem(string $key): bool
    {
        return $this->traitHasItem($key);
    }

    /**
     * Invalidates all cache entries associated with a specific tag.
     *
     * This method removes all cache items that have been tagged with the given tag.
     * It uses an internal tag index to efficiently locate and invalidate tagged entries.
     *
     *
     * @throws CacheInvalidArgumentException If the tag is invalid.
     * @throws Psr6InvalidArgumentException If there's an issue with cache operations.
     * @param string $tag The tag to invalidate. All cache entries with this tag will be removed.
     * @phpstan-return bool True if the operation was successful, false otherwise.
     */
    public function invalidateTag(string $tag): bool
    {
        $normalized = $this->normalizeTag($tag);
        $next = $this->currentTagVersion($normalized) + 1;

        return $this->writeTagVersion($normalized, $next);
    }

    /**
     * Invalidates all cache entries associated with multiple tags.
     *
     * This method iterates through each tag and invalidates all cache entries
     * associated with that tag. The operation is successful only if all tags
     * are successfully invalidated.
     *
     *
     * @throws CacheInvalidArgumentException If any tag is invalid.
     * @throws Psr6InvalidArgumentException If there's an issue with cache operations.
     * @param array $tags An array of tags to invalidate.
     * @phpstan-param array<int, string> $tags An array of tags to invalidate.
     * @phpstan-return bool True if all tags were successfully invalidated, false if any failed.
     */
    public function invalidateTags(array $tags): bool
    {
        $ok = true;
        $seen = [];

        foreach ($tags as $tag) {
            $normalized = $this->normalizeTag((string) $tag);
            if (isset($seen[$normalized])) {
                continue;
            }

            $seen[$normalized] = true;
            $next = $this->currentTagVersion($normalized) + 1;
            $ok = $this->writeTagVersion($normalized, $next) && $ok;
        }

        return $ok;
    }

    /**
     * Required by interface ArrayAccess.
     *
     * {@inheritdoc}
     *
     *
     * @throws Psr6InvalidArgumentException
     *
     * @see has()
     * @param mixed $offset The offset argument.
     * @phpstan-param string $offset
     */
    public function offsetExists(mixed $offset): bool
    {
        return $this->has($offset);
    }

    /**
     * Retrieves the value for the specified offset from the cache.
     *
     * This method allows the use of array-like syntax to retrieve a value
     * from the cache. The offset is converted to a string before retrieval.
     *
     *
     * @throws SimpleCacheInvalidArgument|Psr6InvalidArgumentException if the key is invalid
     * @param mixed $offset The key at which to retrieve the value.
     * @phpstan-param string $offset The key at which to retrieve the value.
     * @phpstan-return mixed The value at the specified offset.
     */
    public function offsetGet(mixed $offset): mixed
    {
        return $this->get($offset);
    }

    /**
     * Sets a value in the cache at the specified offset.
     *
     * This method allows the use of array-like syntax to store a value
     * in the cache. The offset is converted to a string before storing.
     * The time-to-live (TTL) for the cache entry is set to null by default.
     *
     *
     * @throws SimpleCacheInvalidArgument if the key is invalid
     * @param mixed $offset The key at which to set the value.
     * @phpstan-param string $offset The key at which to set the value.
     * @param mixed $value The value to be stored at the specified offset.
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->set($offset, $value);
    }

    /**
     * Unsets a key from the cache.
     *
     *
     * @throws Psr6InvalidArgumentException|SimpleCacheInvalidArgument if the key is invalid
     * @param mixed $offset The offset argument.
     * @phpstan-param string $offset
     */
    public function offsetUnset(mixed $offset): void
    {
        $this->delete($offset);
    }

    /**
     * Compute-once helper with cache stampede protection.
     *
     * On cache miss, this acquires a host-local lock, re-checks cache, computes,
     * applies jittered TTL, persists, and returns the computed value.
     *
     * @param string $key The key argument.
     * @param callable $resolver The resolver argument.
     * @param mixed $ttl The ttl argument.
     * @param array $tags The tags argument.
     * @phpstan-param array<int, string> $tags
     */
    public function remember(
        string $key,
        callable $resolver,
        mixed $ttl = null,
        array $tags = [],
    ): mixed {
        return $this->traitRemember($key, $resolver, $ttl, $tags);
    }

    /**
     * Persists a cache item immediately.
     *
     * This method will throw a Psr6InvalidArgumentException if the item does not
     * implement CacheItemInterface.
     *
     *                                 The cache item to persist.
     *              True if the cache item was successfully persisted, false otherwise.
     *
     * @throws Psr6InvalidArgumentException
     *                                      If the item does not implement CacheItemInterface.
 * @param CacheItemInterface $item The item argument.
     */
    public function save(CacheItemInterface $item): bool
    {
        return $this->adapter->save($item);
    }

    /**
     * Adds a cache item to the deferred queue for later persistence.
     *
     * This method queues the given cache item, to be saved when the
     * `commit()` method is invoked. It does not persist the item immediately.
     *
     * @param CacheItemInterface $item The cache item to defer.
     * @phpstan-return bool True if the item was successfully deferred, false if the item type is invalid.
     */
    public function saveDeferred(CacheItemInterface $item): bool
    {
        return $this->adapter->saveDeferred($item);
    }

    /**
     * Persists a value in the cache, optionally with a TTL.
     *
     *
     * @throws SimpleCacheInvalidArgument if the key or TTL is invalid
     * @param mixed $ttl Time-to-live in seconds or a DateInterval
     * @param string $key The key argument.
     * @param mixed $value The value argument.
     * @phpstan-param int|DateInterval|null $ttl Time-to-live in seconds or a DateInterval
     */
    public function set(string $key, mixed $value, mixed $ttl = null): bool
    {
        $this->validateKey($key);
        $ttlSeconds = $this->normalizeTtl($ttl);

        $result = false;
        if (method_exists($this->adapter, 'set')) {
            try {
                $result = $this->adapter->set($key, $value, $ttlSeconds);
            } catch (Psr6InvalidArgumentException $e) {
                throw new CacheInvalidArgumentException($e->getMessage(), 0, $e);
            }
        } else {
            // Fall back to PSR-6 approach
            try {
                $item = $this->adapter->getItem($key)->set($value)->expiresAfter($ttlSeconds);
                $result = $this->save($item);
            } catch (Psr6InvalidArgumentException $e) {
                throw new CacheInvalidArgumentException($e->getMessage(), 0, $e);
            }
        }

        if ($result) {
            $this->clearTagMeta($key);
            $this->metric('set');
        }

        return (bool) $result;
    }

    public function setLockProvider(LockProviderInterface $lockProvider): self
    {
        $this->lockProvider = $lockProvider;

        return $this;
    }

    public function setMetricsCollector(CacheMetricsCollectorInterface $metrics): self
    {
        $this->metrics = $metrics;

        return $this;
    }

    public function setMetricsExportHook(?callable $hook): self
    {
        $this->metricsExportHook = $hook !== null ? Closure::fromCallable($hook) : null;

        return $this;
    }

    /**
     * Persists multiple key ⇒ value pairs to the cache.
     *
     *
     * @throws SimpleCacheInvalidArgument if any key is invalid
     * @param iterable $values key ⇒ value mapping
     * @phpstan-param iterable<int|string, mixed> $values key ⇒ value mapping
     * @param mixed $ttl TTL for all items
     * @phpstan-param int|DateInterval|null $ttl TTL for all items
     */
    public function setMultiple(iterable $values, mixed $ttl = null): bool
    {
        $ttlSeconds = $this->normalizeTtl($ttl);
        $allSucceeded = true;

        foreach ($values as $k => $v) {
            /** @var string $k */
            $this->validateKey($k);
            $ok = $this->set($k, $v, $ttlSeconds);
            if (!$ok) {
                $allSucceeded = false;
            }
        }

        return $allSucceeded;
    }

    /**
     * Changes the namespace and directory for the pool.
     *
     * If the adapter supports namespace and directory switching, this call is
     * forwarded to it. Otherwise, a {@see BadMethodCallException} is thrown.
     *
     * @throws BadMethodCallException if the adapter does not support this method.
     * @param string $namespace The new namespace.
     * @param string|null $dir The new directory, or null to use the default.
     */
    public function setNamespaceAndDirectory(string $namespace, ?string $dir = null): void
    {
        if (method_exists($this->adapter, 'setNamespaceAndDirectory')) {
            $this->adapter->setNamespaceAndDirectory($namespace, $dir);

            return;
        }

        throw new BadMethodCallException(
            sprintf('%s does not support setNamespaceAndDirectory()', $this->adapter::class),
        );
    }

    /**
     * Stores a value and associates it with one or more tags.
     *
     * This method allows you to tag cache entries for later bulk invalidation.
     * Tags provide a way to group related cache items and invalidate them
     * together when the underlying data changes.
     *
     *
     * @throws CacheInvalidArgumentException If the key or tags are invalid.
     * @throws SimpleCacheInvalidArgument If the key or TTL is invalid.
     * @param string $key The cache key under which to store the value.
     * @param mixed $value The value to store in the cache.
     * @param array $tags An array of tags to associate with this cache entry.
     * @phpstan-param array<int, string> $tags An array of tags to associate with this cache entry.
     * @param mixed $ttl Optional time-to-live for the cache entry.
     * @phpstan-param int|DateInterval|null $ttl Optional time-to-live for the cache entry.
     * @phpstan-return bool True if the operation was successful, false otherwise.
     */
    public function setTagged(string $key, mixed $value, array $tags, mixed $ttl = null): bool
    {
        $normalizedTags = $this->normalizeTagList($tags);
        $ok = $this->set($key, $value, $ttl);
        if (!$ok) {
            return false;
        }

        $ttlSeconds = $this->normalizeTtl($ttl);

        return $this->writeTagMeta($key, $normalizedTags, $ttlSeconds);
    }

    public function useMemcachedLock(?\Memcached $client = null, string $prefix = 'cachelayer:lock:'): self
    {
        if (!$client && method_exists($this->adapter, 'getClient')) {
            $candidate = $this->adapter->getClient();
            if ($candidate instanceof \Memcached) {
                $client = $candidate;
            }
        }

        if (!$client instanceof \Memcached) {
            throw new CacheInvalidArgumentException('Memcached lock provider requires a Memcached client instance.');
        }

        return $this->setLockProvider(new MemcachedLockProvider($client, $prefix));
    }

    public function useRedisLock(?\Redis $client = null, string $prefix = 'cachelayer:lock:'): self
    {
        if (!$client && method_exists($this->adapter, 'getClient')) {
            $candidate = $this->adapter->getClient();
            if ($candidate instanceof \Redis) {
                $client = $candidate;
            }
        }

        if (!$client instanceof \Redis) {
            throw new CacheInvalidArgumentException('Redis lock provider requires a Redis client instance.');
        }

        return $this->setLockProvider(new RedisLockProvider($client, $prefix));
    }

    public function useValkeyLock(?\Redis $client = null, string $prefix = 'cachelayer:lock:'): self
    {
        return $this->useRedisLock($client, $prefix);
    }

    private function applyJitteredTtl(CacheItemInterface $item): void
    {
        if (!$item instanceof AbstractCacheItem) {
            return;
        }

        $ttl = $item->ttlSeconds();
        if ($ttl === null || $ttl <= 1) {
            return;
        }

        $maxJitter = max(1, intdiv($ttl * self::STAMPEDE_JITTER_PERCENT, 100));
        $jitter = random_int(0, $maxJitter);
        $item->expiresAfter(max(1, $ttl - $jitter));
    }

    private function clearTagMeta(string $key): void
    {
        $this->adapter->deleteItem($this->tagMetaKey($key));
    }

    private function currentTagVersion(string $normalizedTag): int
    {
        $key = $this->tagVersionKey($normalizedTag);
        $item = $this->adapter->getItem($key);
        if ($item->isHit() && is_int($item->get()) && $item->get() > 0) {
            return $item->get();
        }

        $item->set(1)->expiresAfter(null);
        $this->adapter->save($item);

        return 1;
    }

    private function isTagMetaValid(string $key): bool
    {
        $metaItem = $this->adapter->getItem($this->tagMetaKey($key));
        if (!$metaItem->isHit()) {
            return true;
        }

        $meta = $metaItem->get();
        if (!is_array($meta)) {
            return false;
        }

        foreach ($meta as $tag => $expectedVersion) {
            if (!is_string($tag) || !is_int($expectedVersion)) {
                return false;
            }

            if ($this->currentTagVersion($tag) !== $expectedVersion) {
                return false;
            }
        }

        return true;
    }

    private function metric(string $name): void
    {
        $this->metrics->increment($this->adapter::class, $name);
    }

    private function normalizeTag(string $tag): string
    {
        $tag = trim($tag);
        if ($tag === '') {
            throw new CacheInvalidArgumentException('Cache tag cannot be empty.');
        }

        return preg_replace('/[^A-Za-z0-9_.\-]/', '_', $tag) ?? '';
    }

    /**
     * @param array $tags The tags argument.
     * @phpstan-param array<int, string> $tags
     * @phpstan-return array<int, string>
     */
    private function normalizeTagList(array $tags): array
    {
        $out = [];
        foreach ($tags as $tag) {
            $out[] = $this->normalizeTag((string) $tag);
        }

        return array_values(array_unique($out));
    }

    /**
     * Converts a PSR-16 TTL (int|DateInterval|null) into an integer number of seconds.
 * @param mixed $ttl The ttl argument.
     */
    private function normalizeTtl(mixed $ttl): ?int
    {
        if ($ttl === null) {
            return null;
        }

        if (is_int($ttl)) {
            return $ttl >= 0 ? $ttl : throw new CacheInvalidArgumentException('Negative TTL not allowed');
        }

        if ($ttl instanceof DateInterval) {
            $now = new DateTime();

            return max(0, $now->add($ttl)->getTimestamp() - (new DateTime())->getTimestamp());
        }

        throw new CacheInvalidArgumentException(
            sprintf(
                'Invalid TTL type; expected null, int, or DateInterval, got %s',
                get_debug_type($ttl),
            ),
        );
    }

    private function purgeKeyAndTagMeta(string $key): void
    {
        $this->adapter->deleteItem($key);
        $this->adapter->deleteItem($this->tagMetaKey($key));
    }

    private function readableAdapterName(string $adapterClass): string
    {
        $short = $adapterClass;
        if (str_contains($short, '\\')) {
            $parts = explode('\\', $short);
            $short = end($parts);
        }

        if (str_ends_with($short, 'CacheAdapter')) {
            $short = substr($short, 0, -strlen('CacheAdapter'));
        }

        return match ($short) {
            'Array' => 'memory',
            'MemCache' => 'memcache',
            'Null' => 'null_store',
            'PhpFiles' => 'php_files',
            'SharedMemory' => 'shared_memory',
            'WeakMap' => 'weak_map',
            'RedisCluster' => 'redis_cluster',
            'Valkey' => 'valkey',
            'ScyllaDb' => 'scylladb',
            'MongoDb' => 'mongodb',
            default => strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $short)),
        };
    }

    /**
     * @param array $snapshot The snapshot argument.
     * @phpstan-param array<string, array<string, int>> $snapshot
     * @phpstan-return array<string, array<string, int>>
     */
    private function readableMetricsSnapshot(array $snapshot): array
    {
        $readable = [];

        foreach ($snapshot as $adapterClass => $counters) {
            $name = $this->readableAdapterName((string) $adapterClass);
            foreach ($counters as $metric => $count) {
                $readable[$name][$metric] = ($readable[$name][$metric] ?? 0) + (int) $count;
            }
        }

        return $readable;
    }

    private function stampedeLockKey(string $key): string
    {
        return '__im_lock_' . hash('sha256', $key);
    }

    private function tagMetaKey(string $key): string
    {
        return self::TAG_META_PREFIX . hash('sha256', $key);
    }

    private function tagVersionKey(string $normalizedTag): string
    {
        return self::TAG_VERSION_PREFIX . hash('sha256', $normalizedTag);
    }

    /**
     * Validates a cache key per PSR-16 rules (and reuses for PSR-6).
     *
     * @throws CacheInvalidArgumentException if the key is invalid.
 * @param string $key The key argument.
     */
    private function validateKey(string $key): void
    {
        if ($key === '' || !preg_match('/^[A-Za-z0-9_.\-]+$/', $key)) {
            throw new CacheInvalidArgumentException(
                'Invalid cache key; allowed characters: A-Z, a-z, 0-9, _, ., -',
            );
        }
    }

    /**
     * @param string $key The key argument.
     * @param array $tags The tags argument.
     * @param int|null $ttl The ttl argument.
     * @phpstan-param array<int, string> $tags
     */
    private function writeTagMeta(string $key, array $tags, ?int $ttl): bool
    {
        if ($tags === []) {
            $this->clearTagMeta($key);

            return true;
        }

        $versions = [];
        foreach (array_values(array_unique($tags)) as $tag) {
            $normalized = $this->normalizeTag((string) $tag);
            $versions[$normalized] = $this->currentTagVersion($normalized);
        }

        $metaItem = $this->adapter->getItem($this->tagMetaKey($key));
        $metaItem->set($versions);
        $metaItem->expiresAfter($ttl);

        return $this->adapter->save($metaItem);
    }

    private function writeTagVersion(string $normalizedTag, int $version): bool
    {
        $item = $this->adapter->getItem($this->tagVersionKey($normalizedTag));
        $item->set(max(1, $version))->expiresAfter(null);

        return $this->adapter->save($item);
    }
}
