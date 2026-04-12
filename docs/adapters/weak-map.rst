.. _adapters.weak_map:

===============================
WeakMap Adapter (``weakMap``)
===============================

Factory: ``Cache::weakMap(string $namespace = 'default')``

Hybrid in-process adapter:

* scalar/array values stored as encoded blobs
* object values stored via ``WeakReference``/``WeakMap``

Object entries remain available while strongly referenced elsewhere. When an
object is collected, its cache entry can disappear naturally.

Use when you specifically want object lifecycle-aware caching.

Example
-------

.. code-block:: php

   use Infocyph\CacheLayer\Cache\Cache;

   $cache = Cache::weakMap('objects');
   $dto = (object) ['id' => 42, 'name' => 'Ada'];

   $cache->set('dto:42', $dto, 30);
   $sameObject = $cache->get('dto:42');
