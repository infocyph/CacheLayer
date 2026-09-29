.. _adapters.php_files:

PHP Files Adapter (``phpFiles``)
================================

Factory: ``Cache::phpFiles(string $namespace = 'default', ?string $dir = null)``

Persists cache records as PHP files that return payload arrays.

Path layout:

* base dir: provided ``$dir`` or ``sys_get_temp_dir() . '/cachelayer/phpfiles'``
* namespace dir: ``cache_<validated-namespace>`` with separate ``data`` and ``meta`` subdirectories
* file name: ``hash('xxh128', $key) . '.php'``

Highlights:

* persistent local cache
* opcode-cache aware (``opcache_invalidate`` on writes/deletes when available)
* OPcache invalidation occurs before replacement or unlink
* immutable namespace and directory configuration

Expired files are removed lazily when encountered. Use bounded operational
directory rotation when entries may expire without being read again.

Good for environments where opcode cache integration is desired.

Use only in trusted environments with a private, application-owned cache root.
The adapter executes each cache PHP file before the returned encoded payload can
be verified by the payload codec. Therefore payload signing does not protect
against an attacker who can replace the executable file or redirect an ancestor,
namespace, or lock path. Construction and lock acquisition reject detected
symlink path components, but deployment ownership and permissions remain the
primary trust boundary.

Example
-------

.. code-block:: php

   use Infocyph\CacheLayer\Cache\Cache;

   $cache = Cache::phpFiles('view-cache', __DIR__ . '/storage/php-cache');
   $cache->set('compiled.home', $compiledTemplate, 900);

   $compiled = $cache->get('compiled.home');
