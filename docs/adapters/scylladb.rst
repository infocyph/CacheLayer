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
* native Scylla TTL on data rows, plus the absolute expiration timestamp used
  for read-time validation
* binary ``blob`` payload storage without Base64 expansion

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

Native lightweight transaction coordination is opt-in via
``Cache::scylla('coordination', atomicMode: true)``. Use a fresh, dedicated,
untagged namespace; every consumer must enable the same mode. Data writes
and deletes use conditional CQL, reads use SERIAL, and LWT commits use
QUORUM with SERIAL consensus. Do not mix ordinary non-LWT clients with
this namespace. Conditional failure retries are bounded; a missing
``[applied]`` result or transport exception is never replayed blindly.
Bulk operations and clear apply per row, not as a global transaction.
The injected session must honor the native driver consistency options.
The hosted native CQL release smoke exercises these operations on PHP 8.4
and 8.5. PHP 8.6 native-driver certification remains unavailable until an
upstream binary is provided.
