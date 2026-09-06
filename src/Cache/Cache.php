<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cache;

use Closure;
use Infocyph\CacheLayer\Cache\Adapter\AbstractCacheAdapter;
use Infocyph\CacheLayer\Cache\Adapter\AtomicCachePoolInterface;
use Infocyph\CacheLayer\Cache\Adapter\InternalCachePoolInterface;
use Infocyph\CacheLayer\Cache\Item\CacheItem;
use Infocyph\CacheLayer\Cache\Lock\FileLockProvider;
use Infocyph\CacheLayer\Cache\Lock\LockProviderInterface;
use Infocyph\CacheLayer\Cache\Lock\MemcachedLockProvider;
use Infocyph\CacheLayer\Cache\Lock\PdoLockProvider;
use Infocyph\CacheLayer\Cache\Lock\RedisLockProvider;
use Infocyph\CacheLayer\Cache\Metrics\CacheMetricsCollectorInterface;
use Infocyph\CacheLayer\Cache\Metrics\CacheMetricsSnapshot;
use Infocyph\CacheLayer\Cache\Metrics\InMemoryCacheMetricsCollector;
use Infocyph\CacheLayer\Cache\Tiering\TieredPoolFactory;
use Infocyph\CacheLayer\Exceptions\CacheBackendException;
use Infocyph\CacheLayer\Exceptions\CacheInvalidArgumentException;
use MongoDB\Client;
use Psr\Cache\CacheItemInterface;
use Throwable;

final class Cache implements AuthenticationStateCacheInterface, AtomicCacheProviderInterface
{
    private const float LOCK_LEASE_SECONDS = 30.0;

    private const float LOCK_WAIT_SECONDS = 5.0;

    private const int TTL_JITTER_PERCENT = 8;

    private readonly bool $authoritative;

    private readonly CacheOptions $options;

    private ?AtomicCache $atomicCapability = null;

    private bool $authenticationStateLockCapable;

    private LockProviderInterface $lockProvider;

    private ?Closure $metricsExportHook = null;

    public function __construct(
        private readonly InternalCachePoolInterface $adapter,
        ?LockProviderInterface $lockProvider = null,
        private CacheMetricsCollectorInterface $metrics = new InMemoryCacheMetricsCollector(),
        ?CacheOptions $options = null,
        private readonly string $namespace = 'default',
    ) {
        CacheInput::namespace($namespace);
        $this->authoritative = !in_array(
            $adapter::class,
            [Adapter\TieredCacheAdapter::class, Adapter\NullCacheAdapter::class],
            true,
        );
        $this->lockProvider = $lockProvider ?? new FileLockProvider();
        $this->authenticationStateLockCapable = $lockProvider !== null;
        $this->options = $options ?? new CacheOptions();
        if ($adapter instanceof AbstractCacheAdapter) {
            $adapter->configureOptions($this->options);
        }
    }

    public static function apcu(string $namespace = 'default', ?CacheOptions $options = null): self
    {
        return new self(
            new Adapter\ApcuCacheAdapter($namespace),
            new FileLockProvider(),
            options: $options,
            namespace: $namespace,
        );
    }

    public static function file(
        string $namespace = 'default',
        ?string $dir = null,
        ?CacheOptions $options = null,
    ): self {
        return new self(
            new Adapter\FileCacheAdapter($namespace, $dir),
            new FileLockProvider(),
            options: $options,
            namespace: $namespace,
        );
    }

    /** @param list<array{0:string, 1:int, 2:int}> $servers */
    public static function memcached(
        string $namespace = 'default',
        array $servers = [['127.0.0.1', 11211, 0]],
        ?\Memcached $client = null,
        ?CacheOptions $options = null,
    ): self {
        $adapter = new Adapter\MemcachedCacheAdapter($namespace, $servers, $client);

        return new self(
            $adapter,
            new MemcachedLockProvider($adapter->getClient()),
            options: $options,
            namespace: $namespace,
        );
    }

    public static function memory(string $namespace = 'default', ?CacheOptions $options = null): self
    {
        return new self(
            new Adapter\ArrayCacheAdapter($namespace),
            new FileLockProvider(),
            options: $options,
            namespace: $namespace,
        );
    }

    public static function mongodb(
        string $namespace = 'default',
        ?object $collection = null,
        ?object $client = null,
        string $database = 'cachelayer',
        string $collectionName = 'entries',
        string $uri = 'mongodb://127.0.0.1:27017',
        ?CacheOptions $options = null,
    ): self {
        if ($collection !== null) {
            return new self(
                new Adapter\MongoDbCacheAdapter($collection, $namespace),
                options: $options,
                namespace: $namespace,
            );
        }
        if ($client === null) {
            if (!class_exists(Client::class)) {
                throw new CacheInvalidArgumentException(
                    'mongodb/mongodb is required unless a collection/client is provided.',
                );
            }
            $client = new Client($uri);
        }

        return new self(
            Adapter\MongoDbCacheAdapter::fromClient($client, $database, $collectionName, $namespace),
            options: $options,
            namespace: $namespace,
        );
    }

    public static function nullStore(?CacheOptions $options = null): self
    {
        return new self(new Adapter\NullCacheAdapter(), options: $options, namespace: 'null');
    }

    public static function pdo(
        string $namespace = 'default',
        ?string $dsn = null,
        ?string $username = null,
        ?string $password = null,
        ?\PDO $pdo = null,
        string $table = 'cachelayer_entries',
        ?CacheOptions $options = null,
    ): self {
        $adapter = new Adapter\PdoCacheAdapter($namespace, $dsn, $username, $password, $pdo, $table);

        return new self(
            $adapter,
            new PdoLockProvider($adapter->getClient()),
            options: $options,
            namespace: $namespace,
        );
    }

    public static function phpFiles(
        string $namespace = 'default',
        ?string $dir = null,
        ?CacheOptions $options = null,
    ): self {
        return new self(
            new Adapter\PhpFilesCacheAdapter($namespace, $dir),
            new FileLockProvider(),
            options: $options,
            namespace: $namespace,
        );
    }

    public static function redis(
        string $namespace = 'default',
        string $dsn = 'redis://127.0.0.1:6379',
        ?\Redis $client = null,
        ?CacheOptions $options = null,
    ): self {
        $adapter = new Adapter\RedisCacheAdapter($namespace, $dsn, $client);

        return new self(
            $adapter,
            new RedisLockProvider($adapter->getClient()),
            options: $options,
            namespace: $namespace,
        );
    }

    /** @param list<string> $seeds */
    public static function redisCluster(
        string $namespace = 'default',
        array $seeds = ['127.0.0.1:6379'],
        float $timeout = 1.0,
        float $readTimeout = 1.0,
        bool $persistent = false,
        ?object $client = null,
        ?CacheOptions $options = null,
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
            options: $options,
            namespace: $namespace,
        );
    }

    public static function scylla(
        string $namespace = 'default',
        ?object $session = null,
        string $keyspace = 'cachelayer',
        string $table = 'cachelayer_entries',
        int $bucketCount = 128,
        ?CacheOptions $options = null,
    ): self {
        if ($session === null) {
            if (!class_exists(\Cassandra::class)) {
                throw new CacheInvalidArgumentException(
                    'ext-cassandra is required unless a ScyllaDB/Cassandra session is provided.',
                );
            }
            $session = \Cassandra::cluster()->build()->connect($keyspace);
        }

        return new self(
            new Adapter\ScyllaDbCacheAdapter($session, $keyspace, $table, $namespace, $bucketCount),
            options: $options,
            namespace: $namespace,
        );
    }

    public static function sharedMemory(
        string $namespace = 'default',
        int $segmentSize = 16_777_216,
        ?CacheOptions $options = null,
    ): self {
        return new self(
            new Adapter\SharedMemoryCacheAdapter($namespace, $segmentSize),
            new FileLockProvider(),
            options: $options,
            namespace: $namespace,
        );
    }

    public static function sqlite(
        string $namespace = 'default',
        ?string $file = null,
        ?CacheOptions $options = null,
    ): self {
        $path = $file ?? Adapter\PdoCacheAdapter::defaultSqliteFileForNamespace($namespace);

        return self::pdo(namespace: $namespace, dsn: 'sqlite:' . $path, options: $options);
    }

    /** @param list<InternalCachePoolInterface|array<string, mixed>> $tiers */
    public static function tiered(
        array $tiers,
        bool $writeToL1 = true,
        ?CacheOptions $options = null,
        string $namespace = 'tiered',
    ): self {
        $metrics = new InMemoryCacheMetricsCollector();

        return new self(
            new Adapter\TieredCacheAdapter(TieredPoolFactory::fromArray($tiers), $writeToL1, $metrics),
            metrics: $metrics,
            options: $options,
            namespace: $namespace,
        );
    }

    public static function valkey(
        string $namespace = 'default',
        string $dsn = 'valkey://127.0.0.1:6379',
        ?\Redis $client = null,
        ?CacheOptions $options = null,
    ): self {
        $adapter = new Adapter\ValkeyCacheAdapter($namespace, $dsn, $client);

        return new self(
            $adapter,
            new RedisLockProvider($adapter->getClient()),
            options: $options,
            namespace: $namespace,
        );
    }

    public static function weakMap(string $namespace = 'default', ?CacheOptions $options = null): self
    {
        return new self(
            new Adapter\WeakMapCacheAdapter($namespace),
            new FileLockProvider(),
            options: $options,
            namespace: $namespace,
        );
    }

    public function atomic(): ?AtomicCacheInterface
    {
        if (!$this->adapter instanceof AtomicCachePoolInterface) {
            return null;
        }

        return $this->atomicCapability ??= new AtomicCache($this->adapter, $this->options, $this->metrics);
    }

    public function authenticationStateLock(): ?LockProviderInterface
    {
        return match ([$this->authenticationStateLockCapable, $this->authoritative]) {
            [true, true] => $this->lockProvider,
            default => null,
        };
    }

    public function clear(): bool
    {
        return $this->backendBool(fn(): bool => $this->adapter->clear());
    }

    public function commit(): bool
    {
        return $this->backendBool(fn(): bool => $this->adapter->commit());
    }

    public function delete(string $key): bool
    {
        CacheInput::key($key);
        $deleted = $this->backendBool(fn(): bool => $this->adapter->deleteItem($key));
        $this->metric('delete');

        return $deleted;
    }

    public function deleteItem(string $key): bool
    {
        return $this->delete($key);
    }

    public function deleteItems(array $keys): bool
    {
        $keys = CacheInput::keys($keys);
        $deleted = $this->backendBool(fn(): bool => $this->adapter->deleteItems($keys));
        $this->metric('delete_batch');
        $this->metric('delete_batch_keys', count($keys));

        return $deleted;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        return $this->deleteItems(CacheInput::materializeKeys($keys));
    }

    public function exportMetrics(): array
    {
        $snapshot = $this->readableMetricsSnapshot($this->metrics->export());
        if ($this->metricsExportHook !== null) {
            ($this->metricsExportHook)($snapshot);
        }

        return $snapshot;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->metric('get');
        $item = $this->getItem($key);
        if (!$item->isHit()) {
            $this->metric('get_miss');

            return $default;
        }
        $this->metric('get_hit');

        return $item->get();
    }

    public function getItem(string $key): CacheItemInterface
    {
        CacheInput::key($key);
        $item = $this->backend(
            fn(): CacheItemInterface => $this->adapter->getItem($key),
            $this->miss($key),
        );

        return $this->validateTagSnapshot($item);
    }

    /** @return array<string, CacheItemInterface> */
    public function getItems(array $keys = []): array
    {
        $keys = CacheInput::keys($keys);
        if ($keys === []) {
            return [];
        }

        $fetched = $this->backend(fn(): array => $this->fetchItems($keys), []);
        $items = [];
        foreach ($keys as $key) {
            $item = $fetched[$key] ?? null;
            $items[$key] = $item instanceof CacheItemInterface ? $item : $this->miss($key);
        }
        $items = $this->validateTagSnapshots($items);
        $hits = 0;
        foreach ($items as $item) {
            $hits += $item->isHit() ? 1 : 0;
        }
        $this->metric('get_batch');
        $this->metric('get_batch_keys', count($keys));
        $this->metric('get_batch_hits', $hits);
        $this->metric('get_batch_misses', count($keys) - $hits);

        return $items;
    }

    /** @return array<string, mixed> */
    public function getMultiple(iterable $keys, mixed $default = null): array
    {
        $keys = CacheInput::materializeKeys($keys);
        $items = $this->getItems($keys);
        $values = [];
        foreach ($keys as $key) {
            $item = $items[$key];
            $values[$key] = $item->isHit() ? $item->get() : $default;
        }

        return $values;
    }

    public function has(string $key): bool
    {
        return $this->hasItem($key);
    }

    public function hasItem(string $key): bool
    {
        return $this->getItem($key)->isHit();
    }

    public function hasPayloadIntegrity(): bool
    {
        return $this->options->integrityKey !== null;
    }

    public function invalidateTag(string $tag): bool
    {
        return $this->invalidateTags([$tag]);
    }

    public function invalidateTags(array $tags): bool
    {
        $tags = CacheInput::tags($tags);
        $invalidated = $this->backendBool(fn(): bool => $this->adapter->rotateTagGenerations($tags));
        $this->metric(count($tags) === 1 ? 'tag_invalidate' : 'tag_invalidate_batch');

        return $invalidated;
    }

    public function isAuthoritative(): bool
    {
        return $this->authoritative;
    }

    public function isFailOpen(): bool
    {
        return $this->options->failOpen;
    }

    public function offsetExists(mixed $offset): bool
    {
        return $this->has($this->requireStringOffset($offset));
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->get($this->requireStringOffset($offset));
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->set($this->requireStringOffset($offset), $value);
    }

    public function offsetUnset(mixed $offset): void
    {
        $this->delete($this->requireStringOffset($offset));
    }

    /** @param callable(): mixed $resolver */
    public function remember(
        string $key,
        callable $resolver,
        mixed $ttl = null,
        array $tags = [],
    ): mixed {
        CacheInput::key($key);
        $tags = CacheInput::tags($tags);
        $ttl = CacheInput::ttl($ttl);

        $item = $this->getItem($key);
        if ($item->isHit()) {
            $this->metric('remember_hit');

            return $item->get();
        }
        $this->metric('remember_miss');

        $lock = $this->backend(
            fn() => $this->lockProvider->acquire(
                $this->stampedeLockKey($key),
                self::LOCK_WAIT_SECONDS,
                self::LOCK_LEASE_SECONDS,
            ),
            null,
        );
        if ($lock === null) {
            $this->metric('lock_timeout');
            $item = $this->getItem($key);
            if ($item->isHit()) {
                $this->metric('remember_hit');

                return $item->get();
            }

            $generations = $this->captureTagGenerations($tags);
            $this->metric('remember_unlocked_compute');
            $value = $resolver();
            if ($generations !== null && $this->tagGenerationsUnchanged($generations)) {
                $this->storeResolved($key, $value, $ttl, $generations);
            } elseif ($tags !== []) {
                $this->metric('remember_discarded_after_tag_change');
            }

            return $value;
        }
        $this->metric('lock_acquired');

        try {
            $item = $this->getItem($key);
            if ($item->isHit()) {
                $this->metric('remember_hit');

                return $item->get();
            }

            $generations = $this->captureTagGenerations($tags);
            $value = $resolver();
            if (!$this->backend(
                fn(): bool => $this->lockProvider->refresh($lock, self::LOCK_LEASE_SECONDS),
                false,
            )) {
                $this->metric('lock_refresh_failure');
                $this->metric('remember_discarded_after_lock_loss');

                return $value;
            }
            if ($generations === null || !$this->tagGenerationsUnchanged($generations)) {
                if ($tags !== []) {
                    $this->metric('remember_discarded_after_tag_change');
                }

                return $value;
            }
            $this->storeResolved($key, $value, $ttl, $generations);

            return $value;
        } finally {
            $this->backend(function () use ($lock): bool {
                $this->lockProvider->release($lock);

                return true;
            }, false);
        }
    }

    public function save(CacheItemInterface $item): bool
    {
        return $this->backendBool(fn(): bool => $this->adapter->save($item));
    }

    public function saveDeferred(CacheItemInterface $item): bool
    {
        return $this->backendBool(fn(): bool => $this->adapter->saveDeferred($item));
    }

    public function set(string $key, mixed $value, mixed $ttl = null): bool
    {
        CacheInput::key($key);
        $ttlSeconds = CacheInput::ttl($ttl);
        if ($ttlSeconds !== null && $ttlSeconds <= 0) {
            return $this->delete($key);
        }

        $item = $this->miss($key)->set($value)->expiresAfter($ttlSeconds);
        $saved = $this->save($item);
        $this->metric('set');

        return $saved;
    }

    public function setLockProvider(LockProviderInterface $lockProvider): self
    {
        $this->lockProvider = $lockProvider;
        $this->authenticationStateLockCapable = true;

        return $this;
    }

    public function setMetricsCollector(CacheMetricsCollectorInterface $metrics): self
    {
        $this->metrics = $metrics;
        $this->atomicCapability?->setMetricsCollector($metrics);

        return $this;
    }

    public function setMetricsExportHook(?callable $hook): self
    {
        $this->metricsExportHook = $hook === null ? null : Closure::fromCallable($hook);

        return $this;
    }

    /** @param iterable<array-key, mixed> $values */
    public function setMultiple(iterable $values, mixed $ttl = null): bool
    {
        $normalized = [];
        foreach ($values as $key => $value) {
            if (!is_string($key)) {
                throw new CacheInvalidArgumentException('Cache keys must be strings.');
            }
            CacheInput::key($key);
            $normalized[$key] = $value;
        }
        $ttlSeconds = CacheInput::ttl($ttl);
        if ($ttlSeconds !== null && $ttlSeconds <= 0) {
            return $this->deleteItems(array_keys($normalized));
        }

        $items = [];
        foreach ($normalized as $key => $value) {
            $items[$key] = $this->adapter->createItem($key)->set($value)->expiresAfter($ttlSeconds);
        }
        $saved = $this->backendBool(fn(): bool => $this->adapter->saveItems($items));
        $this->metric('set_batch');
        $this->metric('set_batch_keys', count($items));

        return $saved;
    }

    public function setTagged(string $key, mixed $value, array $tags, mixed $ttl = null): bool
    {
        CacheInput::key($key);
        $tags = CacheInput::tags($tags);
        $ttlSeconds = CacheInput::ttl($ttl);
        if ($ttlSeconds !== null && $ttlSeconds <= 0) {
            return $this->delete($key);
        }

        $generations = $this->captureTagGenerations($tags);
        if ($generations === null) {
            return false;
        }

        return $this->setTaggedWithGenerations($key, $value, $generations, $ttlSeconds);
    }

    public function useMemcachedLock(?\Memcached $client = null, string $prefix = 'cachelayer:lock:'): self
    {
        if (!$client instanceof \Memcached) {
            throw new CacheInvalidArgumentException('A Memcached client is required.');
        }

        return $this->setLockProvider(new MemcachedLockProvider($client, $prefix));
    }

    public function useRedisLock(?\Redis $client = null, string $prefix = 'cachelayer:lock:'): self
    {
        if (!$client instanceof \Redis) {
            throw new CacheInvalidArgumentException('A Redis client is required.');
        }

        return $this->setLockProvider(new RedisLockProvider($client, $prefix));
    }

    public function useValkeyLock(?\Redis $client = null, string $prefix = 'cachelayer:lock:'): self
    {
        return $this->useRedisLock($client, $prefix);
    }

    /**
     * @template T
     * @param callable(): T $operation
     * @param T $fallback
     * @return T
     */
    private function backend(callable $operation, mixed $fallback): mixed
    {
        try {
            return $operation();
        } catch (Throwable $failure) {
            $this->metric('backend_failure');
            if (!$this->options->failOpen) {
                throw $failure instanceof CacheBackendException
                    ? $failure
                    : new CacheBackendException('Cache backend operation failed.', 0, $failure);
            }

            return $fallback;
        }
    }

    /** @param callable(): bool $operation */
    private function backendBool(callable $operation): bool
    {
        try {
            $result = $operation();
            if (!$result) {
                $this->metric('backend_failure');
            }

            return $result;
        } catch (Throwable $failure) {
            $this->metric('backend_failure');
            if (!$this->options->failOpen) {
                throw $failure instanceof CacheBackendException
                    ? $failure
                    : new CacheBackendException('Cache backend operation failed.', 0, $failure);
            }

            return false;
        }
    }

    /**
     * @param list<string> $tags
     * @return array<string, string>|null
     */
    private function captureTagGenerations(array $tags): ?array
    {
        if ($tags === []) {
            return [];
        }

        $stored = $this->backend(
            fn(): array => $this->adapter->getTagGenerations($tags),
            null,
        );
        if (!is_array($stored)) {
            return null;
        }

        $generations = [];
        foreach ($tags as $tag) {
            $generation = $stored[$tag] ?? null;
            if (!is_string($generation) || strlen($generation) !== 32 || !ctype_xdigit($generation)) {
                return null;
            }
            $generations[$tag] = strtolower($generation);
        }

        return $generations;
    }

    /**
     * @param list<string> $keys
     * @return array<string, CacheItemInterface>
     */
    private function fetchItems(array $keys): array
    {
        return $this->adapter->multiFetch($keys);
    }

    private function jitteredTtl(?int $ttl): ?int
    {
        if ($ttl === null || $ttl <= 1) {
            return $ttl;
        }

        return max(1, $ttl - random_int(0, max(1, intdiv($ttl * self::TTL_JITTER_PERCENT, 100))));
    }

    private function metric(string $name, int $amount = 1): void
    {
        if ($amount > 0) {
            $this->metrics->increment($this->adapter::class, $name, $amount);
        }
    }

    private function miss(string $key): CacheItemInterface
    {
        return $this->adapter->createItem($key);
    }

    /**
     * @param array<string, array<string, int>> $snapshot
     * @return array<string, array<string, int>>
     */
    private function readableMetricsSnapshot(array $snapshot): array
    {
        return CacheMetricsSnapshot::readable($snapshot);
    }

    private function requireStringOffset(mixed $offset): string
    {
        if (!is_string($offset)) {
            throw new CacheInvalidArgumentException('Cache array offsets must be strings.');
        }

        return $offset;
    }

    /** @param array<string, string> $generations */
    private function setTaggedWithGenerations(
        string $key,
        mixed $value,
        array $generations,
        ?int $ttlSeconds,
    ): bool {
        $item = $this->adapter->createItem($key);
        if (!$item instanceof CacheItem) {
            throw new CacheInvalidArgumentException('Tagged caching requires CacheLayer cache items.');
        }
        $item->set($value)->setTagGenerations($generations)->expiresAfter($ttlSeconds);
        $saved = $this->save($item);
        $this->metric('set_tagged');

        return $saved;
    }

    private function stampedeLockKey(string $key): string
    {
        return 'cachelayer:remember:' . hash('xxh128', $this->namespace . "\0" . $key);
    }

    /** @param array<string, string> $generations */
    private function storeResolved(string $key, mixed $value, ?int $ttl, array $generations): void
    {
        if ($ttl !== null && $ttl <= 0) {
            $this->delete($key);

            return;
        }
        $ttlSeconds = $this->jitteredTtl($ttl);
        if ($generations === []) {
            $this->set($key, $value, $ttlSeconds);

            return;
        }
        $this->setTaggedWithGenerations($key, $value, $generations, $ttlSeconds);
    }

    /** @param array<string, string> $expected */
    private function tagGenerationsUnchanged(array $expected): bool
    {
        if ($expected === []) {
            return true;
        }

        $current = $this->captureTagGenerations(array_keys($expected));

        return $current !== null && $current === $expected;
    }

    private function validateTagSnapshot(CacheItemInterface $item): CacheItemInterface
    {
        if (!$item instanceof CacheItem || !$item->isHit() || $item->getTagGenerations() === []) {
            return $item;
        }
        $tags = array_keys($item->getTagGenerations());
        $generations = $this->backend(
            fn(): array => $this->adapter->getTagGenerations($tags),
            null,
        );
        $this->metric('tag_generation_fetch_batch');
        if (!is_array($generations)) {
            return $this->miss($item->getKey());
        }
        if (CacheTagSnapshots::isCurrent($item, $generations)) {
            return $item;
        }
        $this->backendBool(fn(): bool => $this->adapter->deleteItem($item->getKey()));

        return $this->miss($item->getKey());
    }

    /**
     * @param array<string, CacheItemInterface> $items
     * @return array<string, CacheItemInterface>
     */
    private function validateTagSnapshots(array $items): array
    {
        $tags = CacheTagSnapshots::collectTags($items);
        if ($tags === []) {
            return $items;
        }

        $generations = $this->backend(
            fn(): array => $this->adapter->getTagGenerations($tags),
            null,
        );
        $this->metric('tag_generation_fetch_batch');
        if (!is_array($generations)) {
            return CacheTagSnapshots::missTagged($items, $this->miss(...));
        }
        $validated = CacheTagSnapshots::rejectStale($items, $generations, $this->miss(...));
        $stale = $validated['stale'];
        if ($stale !== []) {
            $this->backendBool(fn(): bool => $this->adapter->deleteItems($stale));
        }

        return $validated['items'];
    }
}
