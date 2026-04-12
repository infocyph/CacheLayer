.. _adapters.php_files:

==============================
PHP Files Adapter (`phpFiles`)
==============================

Factory: `Cache::phpFiles(string $namespace = 'default', ?string $dir = null)`

Persists cache records as PHP files that return payload arrays.

Path layout:

* base dir: provided `$dir` or `sys_get_temp_dir()`
* namespace dir: `phpcache_<sanitized-namespace>`
* file name: `hash('xxh128', $key) . '.php'`

Highlights:

* persistent local cache
* opcode-cache aware (`opcache_invalidate` on writes/deletes when available)
* `setNamespaceAndDirectory()` supported

Good for environments where opcode cache integration is desired.
