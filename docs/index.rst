=================
CacheLayer Manual
=================

CacheLayer is a standalone caching toolkit for PHP 8.3+ with:

* PSR-6 and PSR-16 support behind one facade (`Cache`)
* local, distributed, and cloud cache adapters
* tag-version invalidation (`setTagged`, `invalidateTag`, `invalidateTags`)
* stampede-safe `remember()` with pluggable lock providers
* adapter-level metrics export hooks
* payload compression controls
* value serialization for closures and resources
* process-local memoization helpers (`memoize`, `remember`, `once`)

Quick Start
-----------

.. code-block:: php

   use Infocyph\CacheLayer\Cache\Cache;

   $cache = Cache::memory('app');

   $profile = $cache->remember('user:42', function ($item) {
       $item->expiresAfter(300);
       return ['id' => 42, 'name' => 'Ada'];
   }, tags: ['users']);

   $cache->invalidateTag('users');

.. toctree::
   :maxdepth: 2
   :caption: Guide

   cache
   adapters/index
   metrics-and-locking
   serializer
   memoize
   functions
