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
* base64-encoded payload persistence
* TTL-aware read-time pruning

Supported injected collection methods:

* ``findOne``
* ``updateOne``
* ``deleteOne``
* ``deleteMany``
* ``countDocuments``
