.. _metrics_and_locking:

====================
Metrics and Locking
====================

Metrics
-------

Cache facade metrics API:

* `setMetricsCollector(CacheMetricsCollectorInterface $metrics): self`
* `exportMetrics(): array`
* `setMetricsExportHook(?callable $hook): self`

Default collector is `InMemoryCacheMetricsCollector`.

Metric counters are tracked per adapter class, for example:

* `hit`
* `miss`
* `set`
* `delete`
* `delete_batch`
* `remember_hit`
* `remember_miss`

.. code-block:: php

   use Infocyph\CacheLayer\Cache\Metrics\InMemoryCacheMetricsCollector;

   $cache->setMetricsCollector(new InMemoryCacheMetricsCollector());

   $cache->set('k', 'v');
   $cache->get('k');

   $metrics = $cache->exportMetrics();

Locking and Stampede Protection
-------------------------------

`Cache::remember()` acquires a lock to prevent duplicate recomputation.

Default:

* `FileLockProvider`

Optional providers:

* `RedisLockProvider`
* `MemcachedLockProvider`
* `PdoLockProvider`

Facade helpers:

* `setLockProvider(LockProviderInterface $provider): self`
* `useRedisLock(?Redis $client = null, string $prefix = 'cachelayer:lock:'): self`
* `useMemcachedLock(?Memcached $client = null, string $prefix = 'cachelayer:lock:'): self`

Custom lock providers can implement `LockProviderInterface`:

.. code-block:: php

   interface LockProviderInterface
   {
       public function acquire(string $key, float $waitSeconds): ?LockHandle;
       public function release(?LockHandle $handle): void;
   }

`LockHandle` carries key/token/resource metadata used by providers to release locks safely.

Adapter defaults:

* Redis adapter factory sets `RedisLockProvider`
* Memcached adapter factory sets `MemcachedLockProvider`
* PDO/SQLite adapter factories set `PdoLockProvider`
* all other adapters use `FileLockProvider` by default
