.. _adapters.file:

=======================
File Adapter (``file``)
=======================

Factory: ``Cache::file(string $namespace = 'default', ?string $dir = null)``

Stores one cache payload per file under a namespace directory.

Path layout:

* base dir: provided ``$dir`` or ``sys_get_temp_dir() . '/cachelayer/files'``
* namespace dir: ``cache_<sanitized-namespace>``
* file name: ``hash('xxh128', $key) . '.cache'``

Highlights:

* zero service dependencies
* persists across process restarts
* atomic write flow (``tempnam`` + ``rename``)
* ``setNamespaceAndDirectory()`` supported

Best for local/single-host environments.

Example
-------

.. code-block:: php

   use Infocyph\CacheLayer\Cache\Cache;

   $cache = Cache::file('catalog', __DIR__ . '/storage/cache');

   $cache->setTagged('category:shoes', ['count' => 120], ['catalog'], 300);
   $payload = $cache->get('category:shoes');

   // Flush all catalog-tagged entries after product import.
   $cache->invalidateTag('catalog');
