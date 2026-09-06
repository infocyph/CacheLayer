.. _adapters.mongodb:

=============================
MongoDB Adapter (``mongodb``)
=============================

Factories:

* ``Cache::mongodb(...)``
* ``MongoDbCacheAdapter::fromClient(...)`` (adapter-level)

Requirements:

* ``mongodb/mongodb`` package for default client path, or
* injected collection/client compatible with expected methods

Highlights:

* namespace-scoped document storage
* BSON binary payload persistence without Base64 expansion
* TTL-aware read-time pruning; production deployments should also install a
  TTL index on the expiration field for background cleanup
* native ``$in`` reads and ``bulkWrite()`` mutations
* atomic coordination via ``Cache::atomic()``
* ``setIfAbsent()`` uses the unique document ``_id`` as the claim authority
* stale/expired records are reclaimed with an exact-payload conditional update
* ``compareAndSet()`` compares the decoded live value with PHP ``===`` and
  performs ``updateOne()`` guarded by the exact observed stored payload
* tagged ``compareAndSet()`` is rejected because tag-generation documents
  cannot join that single-document conditional replacement
* ``getAndDelete()`` uses the collection's atomic ``findOneAndDelete()``

Supported injected collection methods:

* ``findOne``
* ``findOneAndDelete``
* ``find``
* ``insertOne``
* ``updateOne``
* ``deleteOne``
* ``deleteMany``
* ``bulkWrite``
* ``countDocuments``

Atomic coordination requires an authoritative primary collection. Use dedicated,
untagged coordination keys rather than coupling an atomic protocol to tag
rotation.

Example
-------

.. code-block:: php

   use Infocyph\CacheLayer\Cache\Cache;

   $cache = Cache::mongodb(
       'analytics',
       database: 'app_cache',
       collectionName: 'entries',
       uri: 'mongodb://127.0.0.1:27017',
   );

   $cache->set('dashboard.kpi', ['orders' => 120, 'refunds' => 4], 120);

   $atomic = $cache->atomic();
   $claimed = $atomic?->setIfAbsent('job.claim.42', true, 300);
   $advanced = $atomic?->compareAndSet('job.state.42', 'queued', 'running', 300);
