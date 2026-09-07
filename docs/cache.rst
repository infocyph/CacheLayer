.. _cache:

Cache facade
============

``Infocyph\CacheLayer\Cache\Cache`` implements PSR-6, PSR-16, ``ArrayAccess``,
``AuthenticationStateCacheInterface``, and ``AtomicCacheProviderInterface``. It
adds generation-tagged records, native bulk operations, bounded stampede
protection, optional atomic coordination, tiering, metrics, and per-instance
payload policy.

Factories
---------

The supported factories are ``memory()``, ``weakMap()``, ``nullStore()``,
``apcu()``, ``file()``, ``phpFiles()``, ``sharedMemory()``, ``redis()``,
``valkey()``, ``redisCluster()``, ``memcached()``, ``pdo()``, ``sqlite()``,
``mongodb()``, ``scylla()``, and ``tiered()``.

There are no compatibility aliases or runtime namespace/directory setters.
Configuration is fixed when the cache is constructed.

Keys, namespaces, tags, and TTL
-------------------------------

Keys, tags, and namespaces are 1--64 characters from ``A-Z``, ``a-z``, ``0-9``,
``_``, ``.``, and ``-``. Namespaces are validated without normalization, so
distinct inputs can never collapse into one cache. A namespace is configuration,
not a prefix encoded into the logical key. For example, use
``Cache::redis('mytm', ...)->get('user')`` rather than ``get('mytm:user')``.
The ``:`` character remains reserved by PSR-6/PSR-16 and is rejected at the
public cache-key boundary; adapters compose namespace/data/metadata prefixes
internally. Bulk input is completely validated before mutation.

Zero or negative TTL deletes an ordinary cache entry. Atomic claim/replace TTLs
instead treat a non-positive resolved TTL as a no-op and return ``false``.
Atomic TTLs accept relative integer seconds, ``DateInterval``, or an absolute
``DateTimeInterface``.

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

Atomic coordination capability
------------------------------

Atomic coordination is optional. Probe the facade instead of inspecting an
adapter class:

.. code-block:: php

   $atomic = $cache->atomic();
   if ($atomic === null) {
       throw new RuntimeException('This cache backend is not atomic-capable.');
   }

   if (!$atomic->setIfAbsent('webhook.claim.42', true, 300)) {
       // A live claim already exists, or fail-open backend handling returned
       // the conditional fallback.
   }

   $advanced = $atomic->compareAndSet(
       'workflow.state.42',
       'pending',
       'running',
       300,
   );

   $state = $atomic->getAndDelete('oauth.state.42');

``setIfAbsent()`` stores the complete encoded value and TTL as one conditional
backend operation. Exactly one concurrent claimant can succeed within the
backend's consistency domain. ``compareAndSet()`` replaces an existing live
logical value only when it matches the expected value with PHP strict equality
(``===``). Absence is distinct from a cached ``null`` value, so use
``setIfAbsent()`` when absence itself is the condition. ``getAndDelete()``
returns and consumes one live value as one atomic backend operation. CacheLayer
never implements these methods as public ``has()/get()`` followed by
``set()/delete()``.

The supported facade backends are:

==========================  ======  ====================================================
Backend                     Atomic  Consistency domain
==========================  ======  ====================================================
Array memory                yes     one PHP process
WeakMap                     yes     one PHP process / adapter instance
Shared memory               yes     one host / shared SysV segment
Redis / Valkey              yes     the supplied authoritative Redis-compatible store
Redis Cluster               yes     the key's stable CacheLayer hash-slot bucket
MongoDB                     yes     the supplied authoritative MongoDB collection
Memcached                   yes     the supplied authoritative Memcached CAS domain
PDO: SQLite                 yes     the supplied SQLite database
PDO: PostgreSQL             yes     the supplied PostgreSQL database
PDO: MySQL / MariaDB        yes     the supplied transactional database
File                        yes     one reliable filesystem ``flock()`` domain
PHP files                   yes     one reliable filesystem ``flock()`` domain
APCu                        no      no arbitrary-value CAS/consume primitive
PDO: other drivers          no      no portable transaction/locking contract is claimed
ScyllaDB                    no      LWT is not mixed with ordinary cache writes
Null store                  no      non-authoritative sink
Tiered cache                no      tiers cannot form one linearizable authority
==========================  ======  ====================================================

Memcached uses native CAS tokens for replacement. Atomic consume linearizes by
CAS-replacing the value with an internal short-lived tombstone; ordinary reads
treat the tombstone as a miss, while ``setIfAbsent()`` can immediately reclaim
it through CAS. The tombstone is never exposed as a logical cache value.

File and PHP-file stores use deterministic per-key ``flock()`` files. Ordinary
``save()`` and ``deleteItem()`` mutations take the same key lock as atomic
operations, while reads remain lock-free over atomic rename replacement. Their
atomic guarantee therefore applies only inside a filesystem domain where
``flock()`` semantics are reliable.

PDO atomic capability is driver-qualified. SQLite uses an immediate writer
transaction; PostgreSQL and MySQL/MariaDB use transactional row locking plus
conflict-safe inserts. Other PDO drivers return no atomic capability rather than
claiming a cross-driver guarantee. Atomic PDO operations require ownership of
the PDO transaction and reject execution inside an already-active caller
transaction.

Use dedicated, untagged keys for portable replay claims, nonces, challenges,
state transitions, one-time state, and similar coordination. Tag invalidation
is a separate cache coordination mechanism and is not part of the portable
atomic linearization boundary. Redis/Valkey, Redis Cluster, MongoDB, and
Memcached reject ``compareAndSet()`` on tagged records because their tag
metadata cannot join the same atomic replacement condition. Process-local and
single-lock-domain backends can validate tag generations inside their atomic
domain, but portable protocols should not depend on tagged CAS behavior.

``atomic()`` returns ``null`` rather than emulating missing backend primitives.
This remains true for a tiered facade even when one or more individual tiers
support atomic operations.

For security-sensitive coordination, prefer ``failOpen: false`` when the caller
must distinguish a backend failure from an ordinary conditional miss. With
``failOpen: true``, atomic backend failures follow the normal CacheLayer runtime
policy: ``setIfAbsent()`` and ``compareAndSet()`` return ``false`` and
``getAndDelete()`` returns the provided default while ``backend_failure`` is
recorded.

Immutable options
-----------------

.. code-block:: php

   use Infocyph\CacheLayer\Cache\Cache;
   use Infocyph\CacheLayer\Cache\CacheOptions;

   function createCache(string $integrityKey): Cache
   {
       return Cache::redis('app', options: new CacheOptions(
           integrityKey: $integrityKey,
           maxPayloadBytes: 8_388_608,
           compressionThreshold: 4096,
           allowClosures: false,
           allowObjects: false,
           failOpen: true,
       ));
   }

Pass deploy-varying values from the application's composition root. CacheLayer
does not read process environment state. Options are isolated per instance and
cannot be changed after record processing begins.

Runtime failure policy
----------------------

Construction and configuration failures throw. With the default
``failOpen=true``, runtime read failures become misses and write/delete failures
return ``false`` while incrementing ``backend_failure``. Set ``failOpen=false``
to propagate the backend exception.

Adapters can be used directly as PSR-6 pools, but CacheLayer's tagging,
stampede protection, atomic capability discovery, metrics, and fail-open policy
live in the ``Cache`` facade. Prefer the facade unless the narrower adapter
behavior is intentional.

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
injected Redis/Valkey/SQL/MongoDB client reads from a replica; applications must
provide a primary/authoritative connection when coordination depends on it.

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
