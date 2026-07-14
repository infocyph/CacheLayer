Node-local Cache
================

``NodeCache`` provides an opinionated node-local cache topology: APCu is the
optional hot L1 and SQLite is the persistent local L2. Every application server
owns a separate cache; this feature provides no cross-server synchronization,
distributed locking, or consistency guarantee.

Setup
-----

.. code-block:: php

   use Infocyph\CacheLayer\Node\NodeCache;
   use Infocyph\CacheLayer\Node\NodeCacheConfig;

   $cache = NodeCache::create(new NodeCacheConfig(
       namespace: 'application',
       sqliteFile: '/var/cache/application/cache.sqlite',
   ));

   $user = $cache->remember('user:42', fn () => loadUser(42), 300, ['users']);

APCu is used only when its extension is enabled. When unavailable, the cache
uses SQLite only; it does not select another storage backend.

Behavior
--------

Reads check APCu before SQLite. A SQLite hit is promoted to APCu with its
remaining TTL. Writes persist to SQLite before APCu. Cache failures are
fail-open by default, so the caller can still resolve the authoritative value.
Set ``failOpen: false`` to make runtime cache failures visible.

Entries, tag metadata, and tag invalidation are local to the current server.
Use bounded TTLs, versioned keys, or application-level invalidation broadcasts
when values must be refreshed across servers.

Maintenance
-----------

Expired entries are treated as misses during reads and are not deleted on the
read path. Run bounded maintenance independently on each server:

.. code-block:: php

   $maintenance = NodeCache::maintenance($config);
   $deleted = $maintenance->pruneExpired(5_000);
   $maintenance->checkpoint();
   $maintenance->optimize();

SQLite location and security
----------------------------

Store the database on a writable local filesystem, outside the source tree and
web root. The application user needs access to the SQLite database, WAL, and
shared-memory files. Network and shared filesystems are unsupported. The
factory rejects symlinked and world-writable cache directories.

Do not use Node Cache for authoritative records, distributed locks, global
counters or rate limits, queues, or security state requiring immediate global
revocation.
