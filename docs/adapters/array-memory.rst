.. _adapters.array_memory:

============================
Array Adapter (``memory``)
============================

Factory: ``Cache::memory(string $namespace = 'default')``

In-process array-backed adapter for fast ephemeral caching.

Characteristics:

* no external dependencies
* not shared across processes
* TTL support via encoded expiration timestamps
* atomic coordination via ``Cache::atomic()`` inside the single PHP process
* useful as a deterministic test implementation of the atomic cache contract
* suitable for tests and simple local memo/cache layers

The atomic capability is process-local only; it is not a distributed
coordination mechanism.

Example:

.. code-block:: php

   $cache = Cache::memory('local');
   $cache->set('foo', 'bar', 10);

   $atomic = $cache->atomic();
   $claimed = $atomic?->setIfAbsent('claim', true, 10);
