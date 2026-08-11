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

Supported injected collection methods:

* ``findOne``
* ``find``
* ``updateOne``
* ``deleteOne``
* ``deleteMany``
* ``bulkWrite``
* ``countDocuments``

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
