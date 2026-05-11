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
* factory auto-configures ``RedisLockProvider`` for ``remember()``

DSN notes:

* host/port parsed from DSN
* optional password and DB selection (``/db-index``) are supported

Example
-------

.. code-block:: php

   use Infocyph\CacheLayer\Cache\Cache;

   $cache = Cache::valkey('api', 'valkey://127.0.0.1:6379/0');

   $payload = $cache->remember('endpoint:/v1/users?page=1', function ($item) {
       $item->expiresAfter(30);
       return fetchApiPayload();
   }, tags: ['users']);
