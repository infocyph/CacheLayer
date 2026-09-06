.. _adapters.redis_cluster:

========================================
Redis Cluster Adapter (``redisCluster``)
========================================

Factory:

``Cache::redisCluster(string $namespace = 'default', array $seeds = ['127.0.0.1:6379'], float $timeout = 1.0, float $readTimeout = 1.0, bool $persistent = false, ?object $client = null)``

Requirements:

* RedisCluster support via ``ext-redis``, or
* injected client exposing ``get``, ``set``, ``setex``, ``del``, ``exists``,
  ``incr``, ``mget``, ``mset``, and ``eval``

Highlights:

* 128 fixed hash-tag buckets for cross-slot-safe grouped operations
* namespace clear replaces each opaque bucket generation
* no permanent key index, stale membership, or cluster-wide scan
* atomic ``setIfAbsent()``, ``compareAndSet()``, and ``getAndDelete()`` through
  the cache facade's optional atomic capability
* atomic operations keep each data key and its bucket-generation key in the
  same Redis Cluster hash slot and use generation-aware Lua scripts
* ``compareAndSet()`` checks the current bucket generation and exact observed
  raw blob in the same Lua execution after PHP ``===`` logical comparison
* tagged ``compareAndSet()`` is rejected because tag-generation keys are
  separate metadata and cannot join that same conditional replacement

Atomic capability
-----------------

``Cache::redisCluster(...)->atomic()`` returns an atomic capability when the
adapter is available. ``setIfAbsent()`` provides one-winner conditional insert
with TTL, ``compareAndSet()`` conditionally advances one existing live value,
and ``getAndDelete()`` consumes a value at most once. All three operations
honor the current bucket generation so a namespace clear cannot resurrect,
replace, or consume stale state.

Use dedicated untagged coordination keys for these primitives. If a backend
failure must be distinguishable from an ordinary conditional miss, construct
the cache with ``failOpen: false``.

Useful when using Redis Cluster topology.

Example
-------

.. code-block:: php

   use Infocyph\CacheLayer\Cache\Cache;

   $cache = Cache::redisCluster(
       'checkout',
       ['10.0.0.11:6379', '10.0.0.12:6379', '10.0.0.13:6379'],
   );

   $cache->set('cart.token.abc', ['items' => 3], 1200);

   $atomic = $cache->atomic();
   $advanced = $atomic?->compareAndSet('checkout.state.42', 'pending', 'paid', 300);
