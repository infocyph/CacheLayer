.. _adapters:

===================
Cache Adapters
===================

CacheLayer ships multiple adapters for different runtime and infrastructure
needs.

Choosing quickly:

* Start with ``file`` or ``pdo`` for most applications.
* Use ``memory``/``apcu`` for fastest local access.
* Use ``redis``/``valkey``/``memcache`` for distributed deployments.
* Use cloud adapters (``mongodb``, ``scyllaDb``) when cache must live outside app hosts.

.. toctree::
   :maxdepth: 1

   array-memory
   weak-map
   null-store
   chain
   file
   php-files
   apcu
   memcached
   redis
   valkey
   redis-cluster
   sqlite
   pdo
   shared-memory
   mongodb
   scylladb
   serialization
