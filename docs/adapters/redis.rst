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
* factory auto-configures ``RedisLockProvider`` for ``remember()`` when using this adapter

DSN notes:

* host/port parsed from DSN
* optional password and DB selection (``/db-index``) are supported

Example
-------

.. code-block:: php

   use Infocyph\CacheLayer\Cache\Cache;

   $cache = Cache::redis('api', 'redis://127.0.0.1:6379/0');

   $response = $cache->remember('endpoint:/v1/users?page=1', function ($item) {
       $item->expiresAfter(30);
       return fetchApiPayload();
   }, tags: ['users']);
