.. _metrics_and_locking:

====================
Metrics and Locking
====================

Metrics
-------

Cache facade metrics API:

* ``setMetricsCollector(CacheMetricsCollectorInterface $metrics): self``
* ``exportMetrics(): array``
* ``setMetricsExportHook(?callable $hook): self``

Default collector is ``InMemoryCacheMetricsCollector``.
Exported snapshots use readable adapter keys such as ``file``, ``pdo``,
``redis``, ``valkey``, ``memory``, ``scylladb``, and ``redis_cluster``.

Metric counters are tracked per adapter name, for example:

* ``hit``
* ``miss``
* ``set``
* ``delete``
* ``delete_batch``
* ``remember_hit``
* ``remember_miss``

.. code-block:: php

   use Infocyph\CacheLayer\Cache\Metrics\InMemoryCacheMetricsCollector;

   $cache->setMetricsCollector(new InMemoryCacheMetricsCollector());

   $cache->set('k', 'v');
   $cache->get('k');

   $metrics = $cache->exportMetrics();
   // ['file' => ['set' => 1, 'hit' => 1, ...]]

Locking and Stampede Protection
-------------------------------

``Cache::remember()`` acquires a lock to prevent duplicate recomputation.

Default:

* ``FileLockProvider``

Optional providers:

* ``RedisLockProvider``
* ``MemcachedLockProvider``
* ``PdoLockProvider``

Facade helpers:

* ``setLockProvider(LockProviderInterface $provider): self``
* ``useRedisLock(?Redis $client = null, string $prefix = 'cachelayer:lock:'): self``
* ``useValkeyLock(?Redis $client = null, string $prefix = 'cachelayer:lock:'): self``
* ``useMemcachedLock(?Memcached $client = null, string $prefix = 'cachelayer:lock:'): self``

Custom lock providers can implement ``LockProviderInterface``:

.. code-block:: php

   interface LockProviderInterface
   {
       public function acquire(
           string $key,
           float $waitSeconds,
           float $leaseSeconds = 30.0,
       ): ?LockHandle;

       public function refresh(?LockHandle $handle, float $leaseSeconds): bool;

       public function release(?LockHandle $handle): void;
   }

``acquire()`` returns ``null`` when ownership cannot be obtained within the
bounded wait. A positive lease is mandatory. Long-running operations should
call ``refresh()`` before an expiring distributed lease elapses and stop safely
when renewal returns ``false``. Always call ``release()`` from ``finally``.

.. code-block:: php

   $handle = $locks->acquire('reports:daily', waitSeconds: 2, leaseSeconds: 30);

   if ($handle !== null) {
       try {
           runReportBatch();

           if (!$locks->refresh($handle, leaseSeconds: 30)) {
               throw new RuntimeException('Report lock ownership was lost.');
           }
       } finally {
           $locks->release($handle);
       }
   }

``LockHandle`` carries the key, random ownership token, provider resource, and
original lease duration. Do not construct or modify handles in application
code.

Provider semantics:

* Redis/Valkey acquire with ``SET NX PX`` and renew/release with token-checked
  Lua scripts.
* Memcached uses ``add`` for acquisition and CAS for ownership-safe renewal and
  release.
* MySQL/MariaDB and PostgreSQL use connection-scoped advisory locks. Renewal
  verifies ownership and connection health; the lock remains held until
  release or connection loss.
* File locks retain an open ``flock`` until release. Renewal verifies that the
  owned file resource is still open.
* SQLite and PDO drivers without native advisory locks use the file provider
  fallback. Use the same writable lock directory in every process that must
  coordinate.

Release is best effort and ownership guarded. Distributed leases may disappear
after expiry, eviction, backend restart, or connection loss; callers must not
assume a successful initial acquisition guarantees permanent ownership.

Adapter defaults:

* Redis adapter factory sets ``RedisLockProvider``
* Valkey adapter factory sets ``RedisLockProvider``
* Memcached adapter factory sets ``MemcachedLockProvider``
* PDO/SQLite adapter factories set ``PdoLockProvider``; SQLite uses its
  file-lock fallback
* all other adapters use ``FileLockProvider`` by default
