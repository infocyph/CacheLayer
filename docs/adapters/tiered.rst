.. _adapters.tiered:

Tiered Adapter (``tiered``)
===========================

Factory: ``Cache::tiered(array $tiers, bool $writeToL1 = true, ?CacheOptions $options = null, string $namespace = 'tiered', bool $authoritativeOnly = false)``

Composes multiple PSR-6 pools into a tiered cache.

Behavior:

* writes are propagated to all tiers
* reads send only remaining misses to each later tier
* lower-tier hits are promoted upward in a batch
* these read-through behaviors apply to ordinary mode; authoritative-only mode
  bypasses all upper tiers

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

Atomic operations are available when there is one atomic-capable pool, or
when constructing the tiered facade with ``authoritativeOnly: true``.
The latter bypasses upper tiers for reads and mutations; configure every
consumer of the coordination namespace consistently. Ordinary multi-tier
read-through mode intentionally does not advertise atomic capability because
independent workers could return stale upper-tier values.

Descriptor-based APCu and ScyllaDB tiers accept the ``'atomic_mode' => true`` descriptor option.
The final tier must expose the atomic capability; probe ``$cache->atomic()``.
