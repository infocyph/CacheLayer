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

SQLite does not provide the cross-process advisory-lock contract required by
``LockProviderInterface``. Its default fallback-backed ``PdoLockProvider``
therefore delegates locking to ``FileLockProvider``. All coordinating processes
must use the same writable lock directory and filesystem. SQLite cannot use
``PdoLockProvider::strict()`` because strict PDO locks require a native
MySQL/MariaDB or PostgreSQL driver.

The PDO adapter configures a 5-second SQLite busy timeout before enabling WAL
journal mode, so concurrent initialization can wait for a competing writer.
The timeout does not replace the application lock used by ``remember()`` or
make SQLite a distributed cache across unrelated filesystems.
PDO atomic mutations use an owned immediate transaction and retry classified
SQLite BUSY errors within the bounded policy described in :doc:`pdo`.
Caller-owned transactions are not replayed.

Example
-------

.. code-block:: php

   use Infocyph\CacheLayer\Cache\Cache;

   $cache = Cache::sqlite('jobs', __DIR__ . '/storage/cache/jobs.sqlite');
   $cache->set('job.run.summary', ['ok' => 12, 'failed' => 1], 300);
