.. _adapters.chain:

=========================
Chain Adapter (`chain`)
=========================

Factory: `Cache::chain(array $pools)`

Composes multiple PSR-6 pools into a tiered cache.

Behavior:

* writes are propagated to all tiers
* reads search from first tier to last tier
* hit in lower tier is promoted upward

Typical layout:

* L1: in-memory (`ArrayCacheAdapter`)
* L2: network cache (`RedisCacheAdapter`)

Example:

.. code-block:: php

   use Infocyph\CacheLayer\Cache\Adapter\ArrayCacheAdapter;
   use Infocyph\CacheLayer\Cache\Adapter\RedisCacheAdapter;

   $cache = Cache::chain([
       new ArrayCacheAdapter('l1'),
       new RedisCacheAdapter('l2'),
   ]);
