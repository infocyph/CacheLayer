.. _cache:

Cache facade
============

``Infocyph\CacheLayer\Cache\Cache`` implements PSR-6, PSR-16, and
``ArrayAccess``. It adds versioned tags, native bulk operations, bounded
stampede protection, tiering, metrics, and per-instance payload policy.

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

Keys and tags are 1--64 characters from ``A-Z``, ``a-z``, ``0-9``, ``_``,
``.``, and ``-``. Bulk input is completely validated before mutation. Zero or
negative TTL deletes the entry.

Tags are stored as a version snapshot inside each record. Missing tag metadata
means version zero. Invalidation atomically increments tag versions; a read
fetches all required versions in one batch and rejects the whole record on any
mismatch.

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

Tiering
-------

.. code-block:: php

   $cache = Cache::tiered([
       ['driver' => 'apcu', 'namespace' => 'app'],
       ['driver' => 'valkey', 'namespace' => 'app'],
   ]);

A batch is read once per tier for only the keys still missing, and lower-tier
hits are promoted upward in a batch. Writes and deletes are one batch per tier.
