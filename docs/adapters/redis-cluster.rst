.. _adapters.redis_cluster:

========================================
Redis Cluster Adapter (``redisCluster``)
========================================

Factory:

``Cache::redisCluster(string $namespace = 'default', array $seeds = ['127.0.0.1:6379'], float $timeout = 1.0, float $readTimeout = 1.0, bool $persistent = false, ?object $client = null)``

Requirements:

* RedisCluster support via ``ext-redis``, or
* injected client exposing ``get``, ``set``, ``setex``, ``del``, ``exists``,
  ``incr``, ``mget``, and ``mset``

Highlights:

* 128 fixed hash-tag buckets for cross-slot-safe grouped operations
* namespace clear advances each bucket epoch
* no permanent key index, stale membership, or cluster-wide scan

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
