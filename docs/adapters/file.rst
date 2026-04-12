.. _adapters.file:

=======================
File Adapter (``file``)
=======================

Factory: ``Cache::file(string $namespace = 'default', ?string $dir = null)``

Stores one cache payload per file under a namespace directory.

Path layout:

* base dir: provided ``$dir`` or ``sys_get_temp_dir()``
* namespace dir: ``cache_<sanitized-namespace>``
* file name: ``hash('xxh128', $key) . '.cache'``

Highlights:

* zero service dependencies
* persists across process restarts
* atomic write flow (``tempnam`` + ``rename``)
* ``setNamespaceAndDirectory()`` supported

Best for local/single-host environments.
