.. _adapters.tiered:

=========================
Tiered Adapter (``tiered``)
=========================

Factory: ``Cache::tiered(array $pools, bool $writeToL1 = true)``

Composes multiple PSR-6 pools into a tiered cache.

Behavior:

* writes are propagated to all tiers
* reads send only remaining misses to each later tier
* lower-tier hits are promoted upward in a batch

Typical layout:

* L1: in-memory (``ArrayCacheAdapter``)
* L2: network cache (``RedisCacheAdapter``)

Example:

.. code-block:: php

   use Infocyph\CacheLayer\Cache\Adapter\ArrayCacheAdapter;
   use Infocyph\CacheLayer\Cache\Adapter\RedisCacheAdapter;

   $cache = Cache::tiered([
       new ArrayCacheAdapter('l1'),
       new RedisCacheAdapter('l2'),
   ]);
