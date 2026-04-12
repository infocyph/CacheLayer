.. _adapters.null_store:

============================
Null Adapter (``nullStore``)
============================

Factory: ``Cache::nullStore()``

No-op adapter that never persists values.

Behavior:

* ``set()`` returns true
* ``get()`` always misses unless default/callable path is used
* ``remember()`` recomputes every call

Useful for disabling caching without changing calling code.

Example
-------

.. code-block:: php

   use Infocyph\CacheLayer\Cache\Cache;

   $cache = Cache::nullStore();

   $cache->set('foo', 'bar');
   $value = $cache->get('foo', 'fallback'); // "fallback"
