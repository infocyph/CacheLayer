Cluster Cache
=============

``ClusterCache`` coordinates cache invalidation across independent Node Cache
instances. Values remain on each node's APCu and SQLite layers; the cluster
distributes only durable key, tag, and namespace invalidation events.

Setup
-----

.. code-block:: php

   use Infocyph\CacheLayer\Cluster\ClusterCache;
   use Infocyph\CacheLayer\Cluster\ClusterCacheConfig;
   use Infocyph\CacheLayer\Node\NodeCacheConfig;

   $cluster = ClusterCache::create(
       node: new NodeCacheConfig('/var/cache/application/cache.sqlite', 'application'),
       cluster: new ClusterCacheConfig('production', gethostname()),
       transport: $transport,
   );

   $cache = $cluster->cache();
   $cluster->invalidateKey('product.42');
   $cluster->consume();

Transport contract
------------------

Provide an implementation of ``InvalidationTransportInterface`` backed by a
durable, replayable event store. A shared database table, Redis Streams, NATS
JetStream, RabbitMQ durable queues, and Kafka are suitable. Plain pub/sub alone
is not suitable because offline nodes would lose invalidation events.

The core package intentionally does not select a transport or add a network
dependency. It includes ``PdoInvalidationTransport`` for an application-supplied
shared PostgreSQL or MySQL PDO connection; do not use SQLite as a shared
multi-node event store. A transport must assign sortable event IDs, retain
events long enough for expected node outages, and compare its cursor format for
recovery.

For PDO-backed authoritative data changes, use the same PDO connection inside
the transaction:

.. code-block:: php

   $pdo->beginTransaction();
   updateProduct($pdo, $product);
   $transport->publishWithinTransaction(
       $pdo,
       InvalidationEvent::key('production', 'application', 'product:42', gethostname()),
   );
   $pdo->commit();

Consumption and recovery
------------------------

Run ``consume()`` from a CLI worker or scheduled task on every application
node. Each node persists its own cursor in its local Node SQLite file. If the
cursor predates the transport's oldest retained event, Cluster Cache clears the
local namespace before resuming from the retained stream.

Consistency and failure behavior
--------------------------------

Cluster Cache provides durable eventual invalidation, not immediate global
consistency. Continue assigning appropriate TTLs to entries. Ordinary cache
reads and writes have no transport dependency. A locally initiated invalidation
reports a transport publish failure; use a transactional outbox with the
authoritative database when database updates and invalidations must be committed
atomically.

Do not use Cluster Cache for authoritative data, shared sessions, distributed
locks, global counters, payment idempotency, or immediate security revocation.
