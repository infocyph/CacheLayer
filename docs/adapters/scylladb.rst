.. _adapters.scylladb:

==================================
ScyllaDB Adapter (``scylla``)
==================================

Factory:

``Cache::scylla(string $namespace = 'default', ?object $session = null, string $keyspace = 'cachelayer', string $table = 'cachelayer_entries', int $bucketCount = 128)``

Requirements:

* injected ScyllaDB/Cassandra session object exposing ``execute()``, or
* ``ext-cassandra`` (for default session creation path)

Highlights:

* keyspace/table-backed cache entries with bounded partition buckets
* bucket-grouped ``IN`` reads and bounded unlogged write batches
* schema bootstrap with ``CREATE TABLE IF NOT EXISTS``
* TTL stored as absolute timestamp in ``expires``

Supported injected session methods:

* ``execute``
* ``prepare`` (optional, used when available)

Example
-------

.. code-block:: php

   use Infocyph\CacheLayer\Cache\Cache;

   $cache = Cache::scylla(
       namespace: 'edge',
       keyspace: 'cachelayer',
       table: 'cachelayer_entries',
   );

   $cache->set('homepage.blocks', $blocks, 45);
