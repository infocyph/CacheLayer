.. _cache:

============================
Cache Facade (``Cache``)
============================

``Infocyph\CacheLayer\Cache\Cache`` is the unified facade for CacheLayer.
It implements:

* PSR-6 (``CacheItemPoolInterface``)
* PSR-16 (``Psr\SimpleCache\CacheInterface``)
* ``ArrayAccess``
* ``Countable``

It also adds tagged invalidation, stampede-safe ``remember()``, lock provider
selection, metrics hooks, and payload compression controls.

CacheLayer was separated from the existing Intermix project for better
standalone visibility and faster cache-specific feature enrichment.

Installation
------------

.. code-block:: bash

   composer require infocyph/cachelayer

Quick Example
-------------

.. code-block:: php

   use Infocyph\CacheLayer\Cache\Cache;

   $cache = Cache::file('app', __DIR__ . '/storage/cache');

   $user = $cache->remember('user:42', function ($item) {
       $item->expiresAfter(300);
       return fetchUserFromDatabase(42);
   }, tags: ['users']);

   $cache->invalidateTag('users');

Factory Methods
---------------

The facade exposes factory methods for all bundled adapters:

* ``Cache::local(string $namespace = 'default', ?string $dir = null)``
* ``Cache::file(string $namespace = 'default', ?string $dir = null)``
* ``Cache::phpFiles(string $namespace = 'default', ?string $dir = null)``
* ``Cache::apcu(string $namespace = 'default')``
* ``Cache::memcache(string $namespace = 'default', array $servers = [['127.0.0.1', 11211, 0]], ?Memcached $client = null)``
* ``Cache::redis(string $namespace = 'default', string $dsn = 'redis://127.0.0.1:6379', ?Redis $client = null)``
* ``Cache::redisCluster(string $namespace = 'default', array $seeds = ['127.0.0.1:6379'], float $timeout = 1.0, float $readTimeout = 1.0, bool $persistent = false, ?object $client = null)``
* ``Cache::sqlite(string $namespace = 'default', ?string $file = null)``
* ``Cache::pdo(string $namespace = 'default', ?string $dsn = null, ?string $username = null, ?string $password = null, ?PDO $pdo = null, string $table = 'cachelayer_entries')``
* ``Cache::memory(string $namespace = 'default')``
* ``Cache::weakMap(string $namespace = 'default')``
* ``Cache::sharedMemory(string $namespace = 'default', int $segmentSize = 16777216)``
* ``Cache::nullStore()``
* ``Cache::chain(array $pools)``
* ``Cache::mongodb(string $namespace = 'default', ?object $collection = null, ?object $client = null, string $database = 'cachelayer', string $collectionName = 'entries', string $uri = 'mongodb://127.0.0.1:27017')``
* ``Cache::dynamoDb(string $namespace = 'default', string $table = 'cachelayer_entries', ?object $client = null, array $config = [])``
* ``Cache::s3(string $namespace = 'default', string $bucket = 'cachelayer', ?object $client = null, array $config = [], string $prefix = 'cachelayer')``

``local()`` chooses APCu when available (``extension_loaded('apcu')`` and ``apcu_enabled()``), otherwise File cache.

``pdo()`` defaults to SQLite (temp-file database per namespace) when DSN/PDO is not provided.
``sqlite()`` is a convenience wrapper over ``pdo()`` for explicit SQLite file selection.

Key and TTL Rules
-----------------

Key validation is strict and shared across PSR-6/PSR-16 calls:

* Allowed characters: ``A-Z``, ``a-z``, ``0-9``, ``_``, ``.``, ``-``
* Empty keys or keys with spaces are rejected
* Invalid keys throw ``Infocyph\CacheLayer\Exceptions\CacheInvalidArgumentException``

TTL handling:

* Supported types: ``null``, ``int``, ``DateInterval``
* Negative TTL is rejected
* TTL ``0`` behaves as immediate expiry (adapters treat it as delete/expired)

PSR-16 Methods
--------------

Common helpers:

* ``get(string $key, mixed $default = null): mixed``
* ``set(string $key, mixed $value, int|DateInterval|null $ttl = null): bool``
* ``delete(string $key): bool``
* ``clear(): bool``
* ``getMultiple(iterable $keys, mixed $default = null): iterable``
* ``setMultiple(iterable $values, int|DateInterval|null $ttl = null): bool``
* ``deleteMultiple(iterable $keys): bool``
* ``has(string $key): bool``

``get()`` callable default
~~~~~~~~~~~~~~~~~~~~~~~~

If ``$default`` is callable, ``get()`` internally uses ``remember()`` semantics.
On miss, the callable is executed and the result is persisted.

.. code-block:: php

   $value = $cache->get('profile:42', function ($item) {
       $item->expiresAfter(120);
       return computeProfile();
   });

PSR-6 Methods
-------------

Standard pool methods are available and delegated to the underlying adapter:

* ``getItem()``
* ``getItems()``
* ``hasItem()``
* ``save()``
* ``saveDeferred()``
* ``commit()``
* ``deleteItem()``
* ``deleteItems()``
* ``clear()``

For adapters that implement ``multiFetch(array $keys)``, ``getItems()`` uses it
for efficient batch retrieval.

Tagged Caching
--------------

CacheLayer uses tag-version invalidation (no full key scans required):

* ``setTagged(string $key, mixed $value, array $tags, mixed $ttl = null): bool``
* ``invalidateTag(string $tag): bool``
* ``invalidateTags(array $tags): bool``

When a tag is invalidated, its internal version is incremented. Entries tagged
with older versions become stale and are treated as misses on read.

.. code-block:: php

   $cache->setTagged('home:feed', $payload, ['feed', 'home'], 300);

   $cache->invalidateTag('feed');
   $cache->get('home:feed'); // null (stale)

Stampede-Safe ``remember()``
--------------------------

``remember()`` protects expensive recomputation with a lock provider:

.. code-block:: php

   $value = $cache->remember('report:daily', function ($item) {
       $item->expiresAfter(60);
       return buildDailyReport();
   }, tags: ['reports']);

Behavior:

1. Read existing value.
2. On miss, acquire lock (``FileLockProvider`` by default).
3. Re-check value under lock.
4. Compute and save value.
5. Apply jitter to TTL to reduce herd effects.
6. Release lock.

Lock provider selection:

* ``setLockProvider(LockProviderInterface $provider): self``
* ``useRedisLock(?Redis $client = null, string $prefix = 'cachelayer:lock:'): self``
* ``useMemcachedLock(?Memcached $client = null, string $prefix = 'cachelayer:lock:'): self``

Factory defaults:

* ``Cache::redis(...)`` auto-configures ``RedisLockProvider``
* ``Cache::memcache(...)`` auto-configures ``MemcachedLockProvider``
* ``Cache::pdo(...)`` / ``Cache::sqlite(...)`` auto-configure ``PdoLockProvider``
* other adapters default to ``FileLockProvider``

Metrics and Export Hooks
------------------------

Methods:

* ``setMetricsCollector(CacheMetricsCollectorInterface $metrics): self``
* ``exportMetrics(): array``
* ``setMetricsExportHook(?callable $hook): self``

Default collector is ``InMemoryCacheMetricsCollector``.

Metrics are grouped by readable adapter name and metric name, for example:

.. code-block:: php

   [
       'file' => [
           'hit' => 10,
           'miss' => 4,
           'set' => 3,
       ],
   ]

Payload Compression
-------------------

Use ``configurePayloadCompression(?int $thresholdBytes = null, int $level = 6)``
to enable compression for encoded payloads.

Notes:

* Compression is applied when payload size meets/exceeds threshold.
* Requires ``gzencode``/``gzdecode`` functions.
* Compression configuration is global (``CachePayloadCodec`` static state).

Payload and Serialization Security
----------------------------------

Methods:

* ``configurePayloadSecurity(?string $integrityKey = null, ?int $maxPayloadBytes = 8388608): self``
* ``configureSerializationSecurity(bool $allowClosurePayloads = true, bool $allowObjectPayloads = true): self``

Example:

.. code-block:: php

   $cache
       ->configurePayloadSecurity(
           integrityKey: 'replace-with-strong-secret',
           maxPayloadBytes: 8_388_608,
       )
       ->configureSerializationSecurity(
           allowClosurePayloads: false,
           allowObjectPayloads: false,
       );

Environment variables:

* ``CACHELAYER_PAYLOAD_INTEGRITY_KEY``
* ``CACHELAYER_MAX_PAYLOAD_BYTES``

Convenience Features
--------------------

Array and magic access:

* ``$cache['key'] = 'value';``
* ``$cache['key'];``
* ``$cache->key = 'value';``
* ``$cache->key;``

Counting:

* ``count($cache)`` delegates to adapter ``Countable`` support when available.

Namespace/Directory Mutation
----------------------------

``setNamespaceAndDirectory(string $namespace, ?string $dir = null): void``
forwards to adapters that support runtime namespace/directory changes.

Supported by:

* File cache adapter
* PHP files cache adapter

Unsupported adapters throw ``BadMethodCallException``.
