.. _adapters.sqlite:

===========================
SQLite Adapter (``sqlite``)
===========================

Factory: ``Cache::sqlite(string $namespace = 'default', ?string $file = null)``

``sqlite`` is a convenience wrapper over the PDO adapter.

Equivalent behavior:

* ``Cache::sqlite($namespace, $file)`` forwards to ``Cache::pdo($namespace, 'sqlite:' . $file)``
* when ``$file`` is ``null``, default path is ``sys_get_temp_dir() . "/cachelayer/pdo/cache_<namespace>.sqlite"``

Use ``Cache::pdo(...)`` directly if you want to switch to MySQL/MariaDB/PostgreSQL
without changing the rest of your cache usage pattern.

Example
-------

.. code-block:: php

   use Infocyph\CacheLayer\Cache\Cache;

   $cache = Cache::sqlite('jobs', __DIR__ . '/storage/cache/jobs.sqlite');
   $cache->set('job:run:summary', ['ok' => 12, 'failed' => 1], 300);
