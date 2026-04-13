=================
CacheLayer Manual
=================

CacheLayer is a standalone caching toolkit for PHP 8.3+ with:

* PSR-6 and PSR-16 support behind one facade (``Cache``)
* local, distributed, and cloud cache adapters
* tag-version invalidation (``setTagged``, ``invalidateTag``, ``invalidateTags``)
* stampede-safe ``remember()`` with pluggable lock providers
* adapter-level metrics export hooks
* payload compression controls
* value serialization for closures and resources
* process-local memoization helpers (``memoize``, ``remember``, ``once``)

Project Background
------------------

CacheLayer was separated from the existing Intermix project for better package
visibility and focused feature enrichment around caching.

How to Use This Manual
----------------------

1. Start with ``cache`` for the unified API and factory overview.
2. Read ``adapters/index`` to choose a backend and copy its example.
3. Follow ``cookbook`` for complete end-to-end process flows.
4. Use ``metrics-and-locking`` for production visibility and stampede control.

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
   cookbook
   metrics-and-locking
   security
   serializer
   memoize
   functions
