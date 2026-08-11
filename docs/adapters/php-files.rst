.. _adapters.php_files:

==============================
PHP Files Adapter (``phpFiles``)
==============================

Factory: ``Cache::phpFiles(string $namespace = 'default', ?string $dir = null)``

Persists cache records as PHP files that return payload arrays.

Path layout:

* base dir: provided ``$dir`` or ``sys_get_temp_dir() . '/cachelayer/phpfiles'``
* namespace dir: ``phpcache_<validated-namespace>`` with separate ``data`` and ``meta`` subdirectories
* file name: ``hash('xxh128', $key) . '.php'``

Highlights:

* persistent local cache
* opcode-cache aware (``opcache_invalidate`` on writes/deletes when available)
* OPcache invalidation occurs before replacement or unlink
* immutable namespace and directory configuration

Expired files are removed lazily when encountered. Use bounded operational
directory rotation when entries may expire without being read again.

Good for environments where opcode cache integration is desired.
Use only in trusted environments, since cache entries are stored as executable
PHP files.

Example
-------

.. code-block:: php

   use Infocyph\CacheLayer\Cache\Cache;

   $cache = Cache::phpFiles('view-cache', __DIR__ . '/storage/php-cache');
   $cache->set('compiled.home', $compiledTemplate, 900);

   $compiled = $cache->get('compiled.home');
