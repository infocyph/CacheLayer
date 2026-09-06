.. _adapters.valkey:

===========================
Valkey Adapter (``valkey``)
===========================

Factory:

``Cache::valkey(string $namespace = 'default', string $dsn = 'valkey://127.0.0.1:6379', ?Redis $client = null)``

Requirements:

* ``ext-redis`` (phpredis client)
* reachable Valkey server

Highlights:

* Redis-protocol compatible adapter for Valkey deployments
* namespace-prefixed keys
* ``MGET`` batch retrieval
* atomic coordination via ``Cache::atomic()``
* ``setIfAbsent()`` uses native ``SET NX`` with TTL in the same operation
* ``getAndDelete()`` uses an atomic Redis-compatible Lua consume operation
* factory auto-configures ``RedisLockProvider`` for ``remember()``
* lock ownership uses random tokens with atomic Redis-compatible lease renewal
  and release

Atomic coordination requires an authoritative Valkey connection. Use dedicated,
untagged coordination keys for replay claims, challenges, and one-time state.

DSN notes:

* host/port parsed from DSN
* optional password and DB selection (``/db-index``) are supported

Example
-------

.. code-block:: php

   use Infocyph\CacheLayer\Cache\Cache;

   $cache = Cache::valkey('api', 'valkey://127.0.0.1:6379/0');

   $payload = $cache->remember(
       'endpoint.v1.users.page.1',
       fn () => fetchApiPayload(),
       ttl: 30,
       tags: ['users'],
   );

   $atomic = $cache->atomic();
   $state = $atomic?->getAndDelete('oauth.state.42');
