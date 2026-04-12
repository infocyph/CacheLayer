.. _adapters.memcached:

=================================
Memcached Adapter (``memcache``)
=================================

Factory:

``Cache::memcache(string $namespace = 'default', array $servers = [['127.0.0.1', 11211, 0]], ?Memcached $client = null)``

Requirements:

* ``ext-memcached``
* reachable Memcached server(s)

Highlights:

* distributed in-memory cache
* ``getMulti`` based batch reads
* factory auto-configures ``MemcachedLockProvider`` for ``remember()`` when using this adapter

You may pass your own preconfigured ``Memcached`` client.

Example
-------

.. code-block:: php

   use Infocyph\CacheLayer\Cache\Cache;

   $cache = Cache::memcache('session', [
       ['127.0.0.1', 11211, 100],
   ]);

   $state = $cache->remember('user:42:state', function ($item) {
       $item->expiresAfter(120);
       return loadSessionState(42);
   });
