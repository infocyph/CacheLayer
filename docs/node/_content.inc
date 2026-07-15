=======================
Node-local Cache
=======================

``NodeCache`` is an opinionated cache for one application server. It combines
an optional APCu L1 (hot, process-local memory) with a SQLite L2 (durable local
disk). The returned object is the usual :class:`Cache` facade, so application
code uses the same PSR-6, PSR-16, tagged-cache, and ``remember()`` API as every
other CacheLayer backend.

Use it when every server may safely hold its own, disposable copy of cached
data. It is the storage foundation for :doc:`/cluster/index`, but it is also
useful on its own.

.. node-overview-start

What Node Cache is and is not
-----------------------------

Node Cache is:

* a local APCu -> SQLite cache topology;
* persistent across PHP-FPM or application-worker restarts when the local
  SQLite file survives;
* compatible with normal ``Cache`` reads, writes, tags, batches, deferred
  writes, metrics, and ``remember()``;
* fail-open by default for APCu and SQLite runtime failures.

Node Cache is not:

* a shared cache between servers or containers;
* a distributed lock, queue, counter, rate limiter, or session store;
* a substitute for an authoritative database;
* an immediate global-revocation mechanism for security-sensitive data.

For cross-node invalidation while retaining local cached values, use
:doc:`/cluster/index`.

Topology and request lifecycle
------------------------------

The topology is deliberately simple:

.. code-block:: text

   PHP request / worker
          |
          v
   Cache facade (PSR-6 / PSR-16 / tags / remember)
          |
          v
   APCu L1, when enabled and available
          |
          | miss
          v
   SQLite L2 on this node's local disk
          |
          | miss
          v
   application resolver / authoritative source

Read lifecycle
~~~~~~~~~~~~~~

1. APCu is checked when enabled.
2. On an APCu miss, SQLite is checked.
3. A SQLite hit is promoted to APCu with its remaining TTL.
4. A miss returns the caller's default, or ``remember()`` resolves the value.
5. Expired rows are misses. They are left for bounded maintenance rather than
   being deleted on every read.

Write lifecycle
~~~~~~~~~~~~~~~

1. A normal write is persisted to SQLite first.
2. A successful SQLite write is copied to APCu when APCu is available.
3. A delete or namespace clear is attempted across both layers.
4. Tagged entries and tag-version metadata are local to this node.

With the default ``failOpen: true``, an L2 failure can fall back to an L1 write
so the request can continue. This is a resilience choice: the L1-only value is
lost when that PHP/APCu process is restarted. Set ``failOpen: false`` when a
storage failure must be surfaced to the application instead.

Requirements and filesystem placement
--------------------------------------

Node Cache requires ``ext-pdo`` with the SQLite driver. APCu is optional.
APCu is used only if all of the following are true:

* ``apcuEnabled`` is ``true`` (the default);
* the APCu extension is loaded;
* APCu is enabled for the executing PHP SAPI.

Put the SQLite database in a writable directory that is local to the node,
outside the repository and web root. SQLite creates companion ``-wal`` and
``-shm`` files, so the directory—not merely the database file—must be writable
by the application account.

Do not use NFS, SMB, a synchronized folder, or another network/shared
filesystem for the Node Cache SQLite file. The factory rejects symlinked and
world-writable cache directories as a safety measure.

Recommended layout:

.. code-block:: text

   /var/cache/my-application/
       node-cache.sqlite
       node-cache.sqlite-wal
       node-cache.sqlite-shm
       locks/                 # optional, used by remember() file locks

Configuration and bootstrap
---------------------------

Create one ``NodeCacheConfig`` for each application node. The SQLite path and
lock directory should be local; the namespace names the logical cache within
that database.

.. code-block:: php

   use Infocyph\CacheLayer\Node\NodeCache;
   use Infocyph\CacheLayer\Node\NodeCacheConfig;

   $config = new NodeCacheConfig(
       sqliteFile: '/var/cache/my-application/node-cache.sqlite',
       namespace: 'catalog',
       lockDirectory: '/var/cache/my-application/locks',
       busyTimeoutMs: 1_000,
       apcuEnabled: true,
       failOpen: true,
   );

   $cache = NodeCache::create($config);

Configuration reference
~~~~~~~~~~~~~~~~~~~~~~~

``sqliteFile``
   Required path to the SQLite database. It must not contain a NUL byte.

``namespace``
   Logical cache partition inside the SQLite database. It defaults to
   ``default`` and is sanitized before use. Use a stable, application-specific
   name such as ``catalog`` or ``billing``.

``lockDirectory``
   Optional directory for the file lock provider used by ``remember()``. Leave
   it ``null`` to use the library default, or point it at a local writable
   directory for an explicit deployment layout.

``busyTimeoutMs``
   SQLite busy timeout in milliseconds. The default is 1,000. Increase it
   cautiously only when legitimate local write contention is observed; it does
   not make a shared/network filesystem safe.

``apcuEnabled``
   Enables APCu L1 when the extension and SAPI support it. Set it to ``false``
   for deterministic SQLite-only behavior, CLI jobs, or troubleshooting.

``failOpen``
   Defaults to ``true``. When true, recoverable layer failures are treated as
   cache degradation where possible. When false, APCu/SQLite failures propagate
   to the caller.

``lockProvider``
   Optional ``LockProviderInterface`` used by ``remember()``. When omitted,
   Node Cache uses its local file-lock default. Supply a shared
   ``RedisLockProvider`` or ``ValkeyLockProvider`` when multiple application
   nodes must coordinate one ``remember()`` resolver.

.. code-block:: php

   use Infocyph\CacheLayer\Cache\Lock\RedisLockProvider;

   $config = new NodeCacheConfig(
       sqliteFile: '/var/cache/my-application/node-cache.sqlite',
       namespace: 'catalog',
       lockProvider: new RedisLockProvider($redis, 'catalog:remember:'),
   );

.. node-operations-start

Normal cache operations
-----------------------

``NodeCache::create()`` returns ``Infocyph\CacheLayer\Cache\Cache``. Do not
reach into its SQLite adapter for ordinary application work. Use the facade.

Simple read and write
~~~~~~~~~~~~~~~~~~~~~

.. code-block:: php

   $cache->set('product.42', ['id' => 42, 'name' => 'Keyboard'], 300);

   $product = $cache->get('product.42');
   $missing = $cache->get('does-not-exist', 'fallback');

   $cache->delete('product.42');
   $cache->clear(); // clears this namespace on this node only

``set()`` accepts an integer TTL, a ``DateInterval``, or ``null``. ``null``
uses no explicit expiry; prefer bounded TTLs for data that can change. A TTL of
zero expires immediately. Valid keys contain only letters, digits, ``_``,
``.``, and ``-``.

Read-through caching with ``remember()``
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

Use ``remember()`` for the normal cache-aside read path. It reads first, then
uses the configured local lock to prevent many concurrent requests on the same
node from doing the same expensive work.

.. code-block:: php

   $product = $cache->remember(
       'product.42',
       function ($item) use ($repository) {
           $item->expiresAfter(300);

           return $repository->find(42);
       },
       tags: ['products', 'product.42'],
   );

The lock is node-local. Two different servers may both recompute a missing
value; that is expected for Node Cache. Use short, suitable TTLs and make the
resolver safe to run more than once.

Tags and invalidation
~~~~~~~~~~~~~~~~~~~~~

Tags group related cached values. CacheLayer invalidates them by changing local
tag versions rather than scanning every cached key.

.. code-block:: php

   $cache->setTagged(
       'category.10.products',
       $products,
       ['products', 'category.10'],
       300,
   );

   $cache->invalidateTag('category.10');
   $cache->invalidateTags(['products', 'search-results']);

Tag invalidation affects this node only. In a multi-node deployment,
``invalidateTag()`` on a Node Cache does not notify other machines; call
``ClusterRuntime::invalidateTag()`` instead when Cluster Cache is configured.

PSR-6 items and deferred writes
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

The facade also acts as a PSR-6 pool. Deferred items are held in memory until
``commit()`` and are then written to SQLite in a batch before APCu is updated.

.. code-block:: php

   $first = $cache->getItem('product.42')
       ->set($product)
       ->expiresAfter(300);
   $second = $cache->getItem('product.43')
       ->set($otherProduct)
       ->expiresAfter(300);

   $cache->saveDeferred($first);
   $cache->saveDeferred($second);
   $cache->commit();

For simple batches, use the PSR-16 methods instead:

.. code-block:: php

   $cache->setMultiple([
       'product.42' => $product,
       'product.43' => $otherProduct,
   ], 300);

   $products = $cache->getMultiple(['product.42', 'product.43']);
   $cache->deleteMultiple(['product.42', 'product.43']);

``saveDeferred()`` is for batching in one process. It does not survive a
process crash before ``commit()`` and is not an asynchronous queue.

Common source-data workflows
----------------------------

Create or update a record
~~~~~~~~~~~~~~~~~~~~~~~~~

After the authoritative database transaction succeeds, either delete the exact
key or invalidate the business tag. Avoid writing a speculative database value
to cache before its transaction commits.

.. code-block:: php

   $database->transaction(function () use ($repository, $input): void {
       $repository->updateProduct(42, $input);
   });

   $cache->delete('product.42');
   $cache->invalidateTags(['products', 'category.10']);

The next reader rebuilds entries through ``remember()``. This approach avoids
having cache state become the source of truth.

Delete a record
~~~~~~~~~~~~~~~

Delete the exact record key and any aggregate tags after the source deletion is
committed:

.. code-block:: php

   $repository->deleteProduct(42);
   $cache->delete('product.42');
   $cache->invalidateTag('products');

When a direct key and its aggregate/list views may be cached, invalidating both
is normal and safe.

.. node-production-start

Maintenance lifecycle
---------------------

Run maintenance independently on every node. Reads correctly ignore expired
rows, but pruning prevents the local SQLite file from accumulating them.

.. code-block:: php

   use Infocyph\CacheLayer\Node\NodeCache;

   $maintenance = NodeCache::maintenance($config);
   $deleted = $maintenance->pruneExpired(5_000);
   $maintenance->checkpoint();
   $maintenance->optimize();

``pruneExpired($limit)``
   Deletes at most ``$limit`` expired rows in the configured namespace. It
   returns the number deleted. Run it periodically and keep the limit bounded.

``checkpoint()``
   Requests a passive SQLite WAL checkpoint. It is suitable for regular
   maintenance because it does not force active writers to stop.

``optimize()``
   Runs SQLite ``PRAGMA optimize``. Schedule it less frequently than pruning,
   for example as part of a daily maintenance job.

A simple scheduled task can perform a small bounded prune every few minutes
and checkpoint periodically. Do not run ``VACUUM`` on the request path; plan it
separately if disk-space reclamation is required.

Example cron schedule
~~~~~~~~~~~~~~~~~~~~~

CacheLayer does not install a cron entry or start a scheduler. Wire the
maintenance API into your application's CLI bootstrap, then schedule that
command with the mechanism your deployment already uses.

.. code-block:: php

   // bin/cache-node-maintenance.php
   $maintenance = NodeCache::maintenance($config);
   $maintenance->pruneExpired(5_000);
   $maintenance->checkpoint();

   // Run optimize less frequently, for example from a separate daily command.

For a traditional cron-based deployment, a conservative starting point is:

.. code-block:: text

   # Every five minutes: bounded expired-row cleanup and passive WAL checkpoint.
   */5 * * * * www-data /usr/bin/php /srv/application/bin/cache-node-maintenance.php

   # Daily: bootstrap a command that calls $maintenance->optimize().
   17 3 * * * www-data /usr/bin/php /srv/application/bin/cache-node-optimize.php

Use your framework scheduler, Kubernetes ``CronJob``, systemd timer, or another
platform scheduler instead when that is how application jobs are operated.
Only one maintenance task is needed per local SQLite file; it is safe to use a
bounded prune limit if overlap cannot be ruled out.

Deployment and process guidance
-------------------------------

* Create the cache directory with ownership restricted to the application
  account before starting PHP-FPM/workers.
* Persist the SQLite file on the node if warm cache across process restarts is
  useful. Treat it as disposable: deleting it only causes cold misses.
* In containers, mount a node-local writable volume. Do not mount the same
  SQLite cache volume into multiple nodes.
* Configure APCu for the PHP SAPI that actually serves traffic. A CLI command
  may not have the same APCu settings as PHP-FPM.
* Use the same namespace for processes that should share a node-local cache;
  use distinct namespaces to isolate applications or domains.
* Keep cache TTLs shorter than the maximum period during which stale data would
  be tolerable.

Observability and failures
--------------------------

``exportMetrics()`` exposes adapter counters, including cache hits/misses and
Node Cache layer events. Export it through your application's telemetry path:

.. code-block:: php

   $metrics = $cache->exportMetrics();

Layer failures are represented by metrics such as APCu/SQLite failures when
``failOpen`` is enabled. Alert on sustained failures, a sharp fall in hit rate,
rapid SQLite file growth, and repeated lock contention. With ``failOpen``
disabled, also handle the propagated cache exception according to the request's
availability requirements.

Troubleshooting
---------------

``APCu is not being used``
   Check that ``apcuEnabled`` is true, the APCu extension is loaded, and APCu is
   enabled for the active SAPI. SQLite-only operation is an intended fallback.

``SQLite database is locked``
   Confirm that the database is on a local filesystem, inspect concurrent local
   writers, then consider a modest ``busyTimeoutMs`` increase. Do not solve
   this by placing the file on a shared filesystem.

``Data looks stale on another server``
   This is expected with Node Cache alone. Add Cluster Cache and consume its
   invalidation stream on every node, or rely on an appropriately short TTL.

``The SQLite file keeps growing``
   Schedule ``pruneExpired()`` and periodic checkpoints. Expired rows are not
   removed by reads. Review TTL choices and cache cardinality as well.

``A cache failure should fail the request``
   Create the configuration with ``failOpen: false`` and ensure the application
   handles the resulting exception at the appropriate boundary.
