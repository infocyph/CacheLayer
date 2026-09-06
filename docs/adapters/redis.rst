.. _adapters.redis:

=========================
Redis Adapter (``redis``)
=========================

Factory:

``Cache::redis(string $namespace = 'default', string $dsn = 'redis://127.0.0.1:6379', ?Redis $client = null)``

Requirements:

* ``ext-redis`` (phpredis)
* reachable Redis server

Highlights:

* distributed cache with namespace key prefixing
* ``MGET`` batch retrieval
* TTL via ``SETEX`` when expiration is set
* atomic coordination via ``Cache::atomic()``
* ``setIfAbsent()`` uses native ``SET NX`` with TTL in the same operation
* ``getAndDelete()`` uses an atomic Lua consume operation
* factory auto-configures ``RedisLockProvider`` for ``remember()`` when using this adapter
* lock ownership uses random tokens with ``SET NX PX`` acquisition and atomic
  Lua renewal/release

Atomic coordination requires an authoritative Redis connection. Do not point
security-sensitive replay/state coordination at an asynchronously replicated
read endpoint. Use dedicated, untagged coordination keys.

DSN notes:

* host/port parsed from DSN
* optional password and DB selection (``/db-index``) are supported

Example
-------

.. code-block:: php

   use Infocyph\CacheLayer\Cache\Cache;

   $cache = Cache::redis('api', 'redis://127.0.0.1:6379/0');

   $response = $cache->remember(
       'endpoint.v1.users.page.1',
       fn () => fetchApiPayload(),
       ttl: 30,
       tags: ['users'],
   );

   $atomic = $cache->atomic();
   $claimed = $atomic?->setIfAbsent('webhook.claim.42', true, 300);
