.. _adapters.pdo:

=========================
PDO Adapter (``pdo``)
=========================

Factory:

``Cache::pdo(string $namespace = 'default', ?string $dsn = null, ?string $username = null, ?string $password = null, ?PDO $pdo = null, string $table = 'cachelayer_entries')``

Requirements:

* ``ext-pdo``
* the target PDO driver for your DSN (``pdo_mysql``, ``pdo_pgsql``, etc.)

Highlights:

* unified SQL adapter for MySQL, MariaDB, PostgreSQL, and other PDO drivers
* defaults to SQLite when no DSN/PDO is provided
* namespace-prefixed row keys (``<ns>:<key>``)
* automatic table/index initialization
* driver-aware upsert strategy:
  - PostgreSQL/SQLite: native ``ON CONFLICT``
  - MySQL/MariaDB: native ``ON DUPLICATE KEY UPDATE``
  - fallback path for other PDO drivers
* batched ``multiFetch()`` via single ``IN (...)`` query

Examples:

.. code-block:: php

   // Default sqlite file under sys temp: /tmp/cachelayer/pdo/cache_<namespace>.sqlite
   $cacheDefault = Cache::pdo('app');

   // MySQL / MariaDB
   $cache = Cache::pdo(
       'app',
       'mysql:host=127.0.0.1;port=3306;dbname=app',
       'user',
       'pass',
   );

   // PostgreSQL
   $cachePg = Cache::pdo(
       'app',
       'pgsql:host=127.0.0.1;port=5432;dbname=app',
       'postgres',
       'postgres',
   );

Typical Usage
-------------

.. code-block:: php

   use Infocyph\CacheLayer\Cache\Cache;

   $cache = Cache::pdo('orders');

   $summary = $cache->remember('orders:summary:today', function ($item) {
       $item->expiresAfter(60);
       return loadOrderSummary();
   }, tags: ['orders']);

   // Invalidate all related records after an order mutation.
   $cache->invalidateTag('orders');
