.. _adapters.array_memory:

============================
Array Adapter (`memory`)
============================

Factory: `Cache::memory(string $namespace = 'default')`

In-process array-backed adapter for fast ephemeral caching.

Characteristics:

* no external dependencies
* not shared across processes
* TTL support via encoded expiration timestamps
* suitable for tests and simple local memo/cache layers

Example:

.. code-block:: php

   $cache = Cache::memory('local');
   $cache->set('foo', 'bar', 10);
