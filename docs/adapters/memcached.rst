.. _adapters.memcached:

=================================
Memcached Adapter (``memcached``)
=================================

Factory:

``Cache::memcached(string $namespace = 'default', array $servers = [['127.0.0.1', 11211, 0]], ?Memcached $client = null)``

Requirements:

* ``ext-memcached``
* reachable Memcached server(s)

Highlights:

* distributed in-memory cache
* ``getMulti`` based batch reads
* TTL-grouped ``setMulti`` batch writes
* namespace clear replaces an opaque namespace generation and never calls server-wide ``flush``
* factory auto-configures ``MemcachedLockProvider`` for ``remember()`` when using this adapter
* lock leases use ``add`` acquisition and CAS-guarded renewal/release so an
  expired owner's cleanup cannot delete a replacement owner's lock

You may pass your own preconfigured ``Memcached`` client.

Example
-------

.. code-block:: php

   use Infocyph\CacheLayer\Cache\Cache;

   $cache = Cache::memcached('session', [
       ['127.0.0.1', 11211, 100],
   ]);

   $state = $cache->remember(
       'user.42.state',
       fn () => loadSessionState(42),
       ttl: 120,
   );
