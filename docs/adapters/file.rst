.. _adapters.file:

=======================
File Adapter (``file``)
=======================

Factory: ``Cache::file(string $namespace = 'default', ?string $dir = null)``

Stores one cache payload per file under a namespace directory.

Path layout:

* base dir: provided ``$dir`` or ``sys_get_temp_dir() . '/cachelayer/files'``
* namespace dir: ``cache_<validated-namespace>``
* separate ``data`` and ``meta`` subdirectories
* file name: ``hash('xxh128', $key) . '.cache'``

Highlights:

* zero service dependencies
* persists across process restarts
* atomic write flow (``tempnam`` + ``rename``)
* restrictive directory validation and atomic metadata replacement
* immutable namespace and directory configuration

Expired files are removed lazily when encountered; applications with very
large, low-read keysets should periodically clear or rotate their cache
directory as an operational maintenance policy.

Best for local/single-host environments.

Example
-------

.. code-block:: php

   use Infocyph\CacheLayer\Cache\Cache;

   $cache = Cache::file('catalog', __DIR__ . '/storage/cache');

   $cache->setTagged('category.shoes', ['count' => 120], ['catalog'], 300);
   $payload = $cache->get('category.shoes');

   // Flush all catalog-tagged entries after product import.
   $cache->invalidateTag('catalog');
