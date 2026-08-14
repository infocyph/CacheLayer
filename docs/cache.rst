.. _cache:

Cache facade
============

``Infocyph\CacheLayer\Cache\Cache`` implements PSR-6, PSR-16, ``ArrayAccess``,
and ``AuthenticationStateCacheInterface``. It adds generation-tagged records,
native bulk operations, bounded stampede protection, tiering, metrics, and
per-instance payload policy.

Factories
---------

The supported factories are ``memory()``, ``weakMap()``, ``nullStore()``,
``apcu()``, ``file()``, ``phpFiles()``, ``sharedMemory()``, ``redis()``,
``valkey()``, ``redisCluster()``, ``memcached()``, ``pdo()``, ``sqlite()``,
``mongodb()``, ``scylla()``, and ``tiered()``.

There are no compatibility aliases or runtime namespace/directory setters.
Configuration is fixed when the cache is constructed.

Keys, tags, and TTL
-------------------

Keys, tags, and namespaces are 1--64 characters from ``A-Z``, ``a-z``, ``0-9``,
``_``, ``.``, and ``-``. Namespaces are validated without normalization, so
distinct inputs can never collapse into one cache. Bulk input is completely
validated before mutation. Zero or negative TTL deletes the entry.

Tags are stored as opaque 128-bit generation snapshots inside each record.
Invalidation replaces generations; a read fetches all required generations in
one batch and rejects the whole record on a missing or mismatched generation.
Consequently, evicted metadata cannot make an old tagged record valid again.

Bulk behavior
-------------

``getMultiple()``, ``setMultiple()``, and ``deleteMultiple()`` call the native
adapter batch contract. ``saveDeferred()`` queues items and ``commit()`` uses
the same native bulk persistence path. Metrics distinguish batch operation
count from key count.

``get()`` defaults
------------------

A callable PSR-16 default is returned unchanged. It is never executed or
cached. Use ``remember()`` for explicit computation:

.. code-block:: php

   $value = $cache->remember(
       'report.daily',
       fn () => buildDailyReport(),
       ttl: 60,
       tags: ['reports'],
   );

The remember path is get, miss, lock, recheck, resolve, save, and release. Lock
waiting is bounded and cache hits perform no lock operation.

Immutable options
-----------------

.. code-block:: php

   use Infocyph\CacheLayer\Cache\Cache;
   use Infocyph\CacheLayer\Cache\CacheOptions;

   $cache = Cache::redis('app', options: new CacheOptions(
       integrityKey: $_ENV['CACHE_INTEGRITY_KEY'],
       maxPayloadBytes: 8_388_608,
       compressionThreshold: 4096,
       allowClosures: false,
       allowObjects: false,
       failOpen: true,
   ));

``CacheOptions::fromEnvironment()`` is the explicit opt-in for
``CACHELAYER_PAYLOAD_INTEGRITY_KEY`` and ``CACHELAYER_MAX_PAYLOAD_BYTES``.
Options are isolated per instance and cannot be changed after record processing
begins.

Runtime failure policy
----------------------

Construction and configuration failures throw. With the default
``failOpen=true``, runtime read failures become misses and write/delete failures
return ``false`` while incrementing ``backend_failure``. Set ``failOpen=false``
to propagate the backend exception.

Adapters can be used directly as PSR-6 pools, but CacheLayer's tagging,
stampede protection, metrics, and fail-open policy live in the ``Cache``
facade. Prefer the facade unless the narrower adapter behavior is intentional.

Authentication-state capability
--------------------------------

Security-sensitive consumers can inspect only the effective policy they need:

.. code-block:: php

   $cache->isFailOpen();
   $cache->hasPayloadIntegrity();
   $cache->isAuthoritative();
   $cache->authenticationStateLock(); // ?LockProviderInterface

``isAuthoritative()`` is false for the null and tiered facades. A tiered cache
cannot safely provide a current monotonic authentication value because a stale
L1 read may hide newer lower-tier state. CacheLayer cannot detect whether an
injected Redis/Valkey/SQL client reads from a replica; applications must provide
a primary/authoritative connection.

``authenticationStateLock()`` returns a provider only when one was explicitly
configured by the facade factory, constructor, or ``setLockProvider()`` and the
cache is authoritative. Direct construction without a lock, ``nullStore()``,
and ``tiered()`` return null. This lets a consumer reject unsupported state
configuration instead of silently coordinating a distributed cache with a
process-local lock.

Tiering
-------

.. code-block:: php

   $cache = Cache::tiered([
       ['driver' => 'apcu', 'namespace' => 'app'],
       ['driver' => 'valkey', 'namespace' => 'app'],
   ]);

A batch is read once per tier for only the keys still missing, and lower-tier
hits are promoted upward in a batch. Writes and deletes are one batch per tier.
