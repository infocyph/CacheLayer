========================
Cluster Cache
========================

``ClusterCache`` coordinates *invalidation* between independent
:doc:`/node/index` instances. It does not copy cache values, APCu memory, or
SQLite files between machines. Normal reads and writes remain local and fast;
a durable shared event stream tells other nodes which local values to discard.

This is a cache-coherence tool for data that may be briefly stale. It provides
durable eventual invalidation, not synchronous global consistency.

.. cluster-overview-start

Conceptual model
----------------

Each node owns a Node Cache and a durable cursor. All nodes in a logical
cluster share one replayable invalidation transport:

.. code-block:: text

                       shared durable event transport
                 +------------------------------------+
                 | key / tag / namespace events        |
                 +------------------------------------+
                         ^                    |
                         | publish            | replay
                         |                    v
   node A  Node Cache + runtime          node B  Node Cache + runtime
   APCu + local SQLite                  APCu + local SQLite
   cursor A                             cursor B

When node A invalidates a value, it removes the local value and publishes an
event. Node B's consumer replays that event and removes its own local value.
The next read on either node obtains a fresh value from the authoritative
source and caches it locally.

What Cluster Cache is and is not
--------------------------------

Cluster Cache is:

* a durable, replayable invalidation protocol on top of Node Cache;
* suitable for multiple application servers with independent local storage;
* able to distribute key, tag, and namespace invalidations;
* tolerant of an offline node when the transport retains events long enough.

Cluster Cache is not:

* a value-replication system or shared cache;
* a replacement for transactions, a database, sessions, or distributed locks;
* a guarantee that all nodes observe a change at the same instant;
* appropriate for payment idempotency, global counters, authorization state
  that needs immediate revocation, or other authoritative/security-critical
  state.

Cluster identity, nodes, and namespaces
---------------------------------------

``ClusterCacheConfig`` has four settings:

.. code-block:: php

   use Infocyph\CacheLayer\Cluster\ClusterCacheConfig;

   $clusterConfig = new ClusterCacheConfig(
       cluster: 'production',
       nodeId: 'catalog-web-01',
       consumerBatchSize: 1_000,
       invalidateLocallyFirst: true,
   );

``cluster``
   A non-empty logical stream name. ``production``, ``staging``, and
   ``tenant-a`` can use the same transport without consuming each other's
   events. There is no fixed CacheLayer limit on cluster names; transport
   capacity and retention determine the practical limit.

``nodeId``
   A non-empty identity for this running node. It must be unique among nodes in
   the same cluster. The producer's own events are not applied a second time,
   but their cursor still advances. A stable hostname is suitable for long-lived
   hosts; use a unique instance/pod identity for ephemeral infrastructure.

``consumerBatchSize``
   Maximum events fetched by ``consume()`` when no explicit limit is supplied.
   It must be greater than zero. Start with 1,000 and tune from observed event
   volume and consumer runtime.

``invalidateLocallyFirst``
   Defaults to ``true``. With the default, the initiating node clears its local
   cache first and then publishes the event. With ``false``, it publishes first
   and clears locally afterward. This selects failure order; it does not make a
   two-system operation atomic. See :ref:`cluster-failure-order`.

The Node Cache ``namespace`` is distinct from the cluster name. A namespace is
stored in every event and remote nodes only apply events for their matching
namespace. This allows independent cache domains to share a cluster transport,
although all events in that cluster are still read to advance each node's
cursor.

Prerequisites and transport choice
----------------------------------

Every node needs:

* a local writable SQLite file and optional APCu, as described in
  :doc:`/node/index`;
* the same cluster name and a unique node ID;
* access to the same durable, replayable event transport;
* a consumer process or scheduled task that calls ``consume()``;
* retention longer than the largest expected node outage plus an operational
  safety margin.

``InvalidationTransportInterface`` is the boundary for the shared event
store. A production transport must assign sortable IDs, replay events after a
cursor, expose its oldest retained ID, and compare its cursor format for
recovery. Suitable technologies include PostgreSQL/MySQL tables, Redis Streams,
NATS JetStream, RabbitMQ durable queues designed for replay, and Kafka.

Plain publish/subscribe is not sufficient: an offline node would lose the
invalidation and retain a stale local entry until its TTL expires.

PDO transport
~~~~~~~~~~~~~

``PdoInvalidationTransport`` is included for a supplied PostgreSQL or MySQL
PDO connection. It creates a shared ``cachelayer_invalidation_events`` table
and index when constructed. The connection account therefore needs the required
DDL and read/write rights when the transport is initialized.

.. code-block:: php

   use Infocyph\CacheLayer\Cluster\Transport\Pdo\PdoInvalidationTransport;

   $pdo = new PDO(
       'pgsql:host=db.internal;port=5432;dbname=application',
       'application',
       $password,
       [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
   );

   $transport = new PdoInvalidationTransport($pdo);

SQLite support in this class is useful for a local test only. Do **not** use a
SQLite database as a shared multi-node event transport in production.

.. cluster-topology-start

Bootstrap one runtime per node
------------------------------

Build the Node Cache configuration, Cluster configuration, and transport once
in your application's service container or bootstrap. Keep the resulting
``ClusterRuntime`` and inject either the runtime or its cache facade where
needed.

.. code-block:: php

   use Infocyph\CacheLayer\Cluster\ClusterCache;
   use Infocyph\CacheLayer\Cluster\ClusterCacheConfig;
   use Infocyph\CacheLayer\Node\NodeCacheConfig;

   $runtime = ClusterCache::create(
       node: new NodeCacheConfig(
           sqliteFile: '/var/cache/catalog/node-cache.sqlite',
           namespace: 'catalog',
           lockDirectory: '/var/cache/catalog/locks',
       ),
       cluster: new ClusterCacheConfig(
           cluster: 'production',
           nodeId: gethostname() ?: 'catalog-unknown-node',
       ),
       transport: $transport,
   );

   $cache = $runtime->cache();

The returned runtime exposes this small coordination API:

===============================  =====================================================
Method                           Meaning
===============================  =====================================================
``cache(): Cache``               The normal local Node Cache facade.
``invalidateKey(string $key)``   Invalidate one key locally and publish an event.
``invalidateTag(string $tag)``   Invalidate one tag locally and publish an event.
``clearNamespace()``             Clear the local namespace and publish an event.
``consume(?int $limit)``         Replay up to ``limit`` events and return the count read.
``recoverIfRequired(): bool``    Perform retention-gap recovery; normally called by consume.
===============================  =====================================================

The critical boundary is ``$runtime->cache()``: all ordinary ``get()``,
``set()``, ``remember()``, ``delete()``, and ``clear()`` calls are **local
only**. Use the runtime's three invalidation methods when an operation must be
distributed.

Adding the third, fourth, or Nth node
-------------------------------------

There is no central cluster object that receives a list of node instances and
no node-registration call. A cluster is formed when independently running
application nodes use the same ``cluster`` name and shared transport. Each
node creates its own runtime, local SQLite file, and consumer.

For example, this bootstrap function is deployed on every web/worker node. The
deployment supplies a different ``$nodeId`` on each machine or instance:

.. code-block:: php

   use Infocyph\CacheLayer\Cluster\ClusterCache;
   use Infocyph\CacheLayer\Cluster\ClusterCacheConfig;
   use Infocyph\CacheLayer\Cluster\ClusterRuntime;
   use Infocyph\CacheLayer\Cluster\Transport\InvalidationTransportInterface;
   use Infocyph\CacheLayer\Node\NodeCacheConfig;

   function bootCatalogCluster(string $nodeId, InvalidationTransportInterface $transport): ClusterRuntime
   {
       return ClusterCache::create(
           node: new NodeCacheConfig(
               sqliteFile: "/var/cache/catalog/{$nodeId}.sqlite",
               namespace: 'catalog',
           ),
           cluster: new ClusterCacheConfig(
               cluster: 'production-catalog', // identical on every node
               nodeId: $nodeId,                // unique on every node
           ),
           transport: $transport,              // points to one shared event store
       );
   }

   $nodeOne = bootCatalogCluster('catalog-web-01', $transport);
   $nodeTwo = bootCatalogCluster('catalog-web-02', $transport);
   $nodeThree = bootCatalogCluster('catalog-web-03', $transport);
   // Continue with any number of nodes.

In a real deployment these three calls run in separate processes/machines—not
in one PHP request. Each process normally creates its own PDO connection to
the same database or its own client connection to the selected transport.

The node-addition flow is:

1. Provision a private local cache directory/SQLite file for the new node.
2. Give it a unique ``nodeId`` while retaining the existing cluster name,
   namespace, and transport configuration.
3. Start its consumer before or alongside application traffic.
4. The new node begins with no cursor, safely reads retained events, and then
   serves local cache reads/writes like every other node.
5. Existing nodes require no configuration change; they already publish into
   the common transport.

When node one executes ``$nodeOne->invalidateKey('product.42')``, it invalidates
its own cache and publishes an event. Nodes two, three, and every later node
apply that event when their individual consumers next call ``consume()``.

.. cluster-operations-start

Local reads and writes
----------------------

Read paths do not contact the transport. This keeps the hot path independent
of the event-store availability.

.. code-block:: php

   // Node-local read through APCu then SQLite; no transport call.
   $product = $runtime->cache()->remember(
       'product.42',
       function ($item) use ($products) {
           $item->expiresAfter(300);

           return $products->find(42);
       },
       tags: ['products', 'product.42'],
   );

Direct writes are local too:

.. code-block:: php

   $runtime->cache()->set('product.42', $product, 300);
   $runtime->cache()->setTagged('catalog.home', $payload, ['products', 'home'], 60);
   $runtime->cache()->setMultiple(['product.42' => $product, 'product.43' => $other], 300);

This is intentional. A cache fill should not broadcast to every node because
each node has its own capacity, traffic pattern, and TTL. Only changes to the
underlying data need cross-node invalidation.

Distributed invalidation operations
-----------------------------------

After a source-data change commits, select the narrowest invalidation that
correctly covers affected entries.

Key invalidation
~~~~~~~~~~~~~~~~

Use a key when one cached representation changed:

.. code-block:: php

   $products->update(42, $input); // authoritative write has committed
   $runtime->invalidateKey('product.42');

This deletes ``product.42`` locally and publishes a key event for other nodes.

Tag invalidation
~~~~~~~~~~~~~~~~

Use tags when a change affects a group, list, or related derived entries:

.. code-block:: php

   $products->update(42, $input);
   $runtime->invalidateTag('products');

Any node that previously stored values with ``['products']`` treats those
entries as stale after consuming the event. You can still delete a direct key
locally if appropriate, then invalidate the shared tag:

.. code-block:: php

   $runtime->cache()->delete('product.42'); // local direct representation
   $runtime->invalidateTag('products');      // distributed aggregate invalidation

Namespace invalidation
~~~~~~~~~~~~~~~~~~~~~~

Use ``clearNamespace()`` for a controlled broad reset, such as a schema or
serialization deployment that invalidates every entry in the namespace:

.. code-block:: php

   $runtime->clearNamespace();

It is intentionally expensive because every participating node clears that
namespace after consuming the event. Prefer a key or tag for routine writes.

End-to-end application workflows
--------------------------------

Read a product
~~~~~~~~~~~~~~

1. Request calls ``$runtime->cache()->remember('product.42', ...)``.
2. The node checks APCu, then local SQLite.
3. On a miss, the resolver reads the authoritative store and caches the value
   locally.
4. No cluster event is created; cache fills are local.

Update a product
~~~~~~~~~~~~~~~~

1. Commit the source-of-truth update.
2. Call ``$runtime->invalidateKey('product.42')`` and/or
   ``$runtime->invalidateTag('products')``.
3. The initiating node invalidates its own local state and publishes events.
4. Other nodes' consumers replay those events and invalidate their local state.
5. Subsequent reads rebuild local entries from the authoritative source.

Delete a product
~~~~~~~~~~~~~~~~

1. Commit the authoritative deletion.
2. Invalidate the direct representation and any affected list/search tags.
3. Allow consumers to propagate the same invalidation to other nodes.

There is no ``ClusterRuntime::invalidateTags()`` method. Publish one event per
tag instead:

.. code-block:: php

   $runtime->invalidateKey('product.42');
   $runtime->invalidateTag('products');
   $runtime->invalidateTag('search-results');

Do not call ``$runtime->cache()->invalidateTags()`` for distributed work; that
would only invalidate the initiating node.

Consumer lifecycle
------------------

Each application node needs a consumer. ``consume()`` performs these steps:

1. Check whether the node's stored cursor is older than retained events.
2. If it is, clear the local namespace and reset the cursor safely.
3. Fetch up to the requested number of events after the current cursor.
4. Ignore events emitted by this same node (they were already applied locally).
5. Apply matching-namespace events from other nodes.
6. Advance the local SQLite cursor after every event, including ignored events.

``consume()`` returns the number of events *read*, not the number applied. It
returns zero when no events are available or when passed a limit below one.

Scheduled polling
~~~~~~~~~~~~~~~~~

For moderate traffic, run a short CLI task on every node every few seconds or
minutes, according to the maximum acceptable stale window:

.. code-block:: php

   // bin/cache-consume.php: bootstrap $runtime for this node first.
   $processed = $runtime->consume();

The schedule determines remote invalidation latency. For example, a task every
five seconds means a healthy remote node may serve stale data for nearly five
seconds plus normal request timing. TTL remains the final safety bound.

Cluster consumers need scheduling
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

CacheLayer deliberately does not run a background daemon or install cron jobs:
the host application owns process supervision, credentials, logging, retries,
and graceful shutdown. However, **every node must run a consumer** for remote
invalidations to become effective. Choose one of these deployment models:

``cron`` or framework scheduler
   Good for modest event volume and a known stale-data budget. Schedule the
   same small CLI command on every application node. The poll interval is part
   of the remote-staleness budget.

Supervised long-running worker
   Good for low-latency or high-volume systems. Run a worker under systemd,
   Supervisor, Kubernetes, or your framework queue/worker runtime. It calls
   bounded ``consume()`` loops and polls/sleeps between iterations.

Transport-native delivery
   A custom transport can integrate with a durable broker's worker model, but
   it still must preserve the replay/cursor contract. CacheLayer's consumer is
   explicitly pull-based and works with scheduled polling for all transports.

For a cron deployment, create a command that only bootstraps the runtime for
the current node and consumes one bounded batch:

.. code-block:: php

   // bin/cache-cluster-consume.php
   $processed = $runtime->consume(1_000);

Then schedule it on **every** node. This example polls every minute; lower the
interval only when the resulting remote-staleness window is acceptable for the
application and the transport can handle the polling rate:

.. code-block:: text

   * * * * * www-data /usr/bin/php /srv/application/bin/cache-cluster-consume.php

Traditional cron cannot reliably poll more often than once a minute. For
sub-minute propagation, use a framework scheduler that supports it or a
supervised worker. Ensure the scheduler prevents overlapping runs on the same
node, or make each invocation deliberately short and bounded.

Continuous worker with bounded drain
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

For higher event volume, use a supervised worker. Keep individual iterations
bounded so one constantly busy stream cannot starve health checks or graceful
shutdown handling in your worker framework:

.. code-block:: php

   $limit = 1_000;
   do {
       $processed = $runtime->consume($limit);
   } while ($processed === $limit);

Then let the worker sleep/poll according to the transport and your supervision
model. CacheLayer does not start a daemon or provide a scheduler; deployment
owns that lifecycle.

Startup and deployment behavior
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

It is safe to call ``consume()`` during worker startup before serving traffic.
That lets a node catch up before it begins filling local cache entries. Retain
events long enough for planned deployments, autoscaling delays, node outages,
and the time needed to drain a backlog.

If a node has a new empty local SQLite file, it has no cursor and begins from
the retained stream. This may replay unnecessary invalidations but is safe. If
a node intentionally changes its ``nodeId``, it gets a distinct cursor record;
ensure the identity remains unique and monitor for unexpected backlog replay.

.. cluster-reliability-start

Retention, recovery, and offline nodes
--------------------------------------

Each node stores ``(cluster, nodeId, lastEventId)`` in its local Node Cache
SQLite database. The transport retains only a finite event history. If a node's
cursor predates the oldest available event, it cannot know every invalidation it
missed. Before normal consumption, Cluster Cache therefore:

1. clears that node's entire local namespace;
2. resets its cursor to the oldest retained event ID;
3. resumes consumption on subsequent reads of the event stream.

``recoverIfRequired()`` exposes this check directly and returns ``true`` when
a clear/recovery occurred. Calling ``consume()`` already calls it, so direct
calls are mainly useful for health checks or explicit startup handling.

This safe reset is why event retention is an availability and cache-warmth
decision rather than a correctness shortcut. Short retention causes more full
local clears after outages; long retention increases transport storage and
catch-up work.

For ``PdoInvalidationTransport``, prune events in small batches after a chosen
retention period:

.. code-block:: php

   // Keep events for seven days. Run this repeatedly from maintenance.
   $deleted = $transport->pruneBefore(time() - 7 * 24 * 60 * 60, 5_000);

The PDO transport's ``pruneBefore()`` is intentionally not part of the generic
transport interface. Other transport implementations should use their own
retention mechanism.

Retention-pruning schedule
~~~~~~~~~~~~~~~~~~~~~~~~~~

Event pruning is separate from consumption. For the PDO transport, schedule a
maintenance command against the shared database once; do not run competing
pruners on every web node unless your job platform coordinates them.

.. code-block:: php

   // bin/cache-cluster-prune.php
   $transport->pruneBefore(time() - 7 * 24 * 60 * 60, 5_000);

.. code-block:: text

   # One elected scheduler/worker, hourly; run repeatedly if the backlog is large.
   12 * * * * www-data /usr/bin/php /srv/application/bin/cache-cluster-prune.php

Keep the retention window longer than the largest supported outage. Pruning
more often does not permit a shorter retention period safely.

.. _cluster-failure-order:

Failure ordering and transactional writes
-----------------------------------------

An invalidation involves two independent actions: local invalidation and event
publication. ``invalidateLocallyFirst`` chooses which happens first.

With the default ``true``:

1. The initiating node becomes fresh immediately.
2. If publication fails, remote nodes are not notified and may be stale until
   TTL or a later successful invalidation.

With ``false``:

1. Remote propagation is recorded before the initiating local clear.
2. If local invalidation fails, the originating node may remain stale while
   remote nodes later become fresh.

Neither sequence is atomic across the source database and the event transport.
For important database writes, use the PDO transactional-outbox helper with
the same database connection:

.. code-block:: php

   use Infocyph\CacheLayer\Cluster\Event\InvalidationEvent;

   $pdo->beginTransaction();
   try {
       updateProduct($pdo, 42, $input);
       $transport->publishWithinTransaction(
           $pdo,
           InvalidationEvent::key(
               'production',
               'catalog',
               'product.42',
               gethostname() ?: 'catalog-unknown-node',
           ),
       );
       $pdo->commit();
   } catch (Throwable $exception) {
       if ($pdo->inTransaction()) {
           $pdo->rollBack();
       }

       throw $exception;
   }

   // The event is now durable. Clear this origin node explicitly after commit.
   $runtime->cache()->delete('product.42');

``publishWithinTransaction()`` requires the exact PDO connection held by the
transport and an active transaction. It makes the source update and event
insertion atomic. It does not itself invalidate the origin node: origin events
are skipped by that node's consumer, so the explicit local delete after a
successful commit is required. Treat a failure of that delete as operationally
important and retry/alert; a bounded TTL still limits the stale window.

Operations checklist
--------------------

At deployment:

* create a local, private cache directory on every node;
* configure one shared durable transport per logical cluster;
* give every node a unique ID and use the same cluster name;
* start a consumer on every node before or alongside application traffic;
* set event retention longer than the expected maximum outage;
* choose bounded cache TTLs even with fast consumers.

During normal operation:

* use ``$runtime->cache()->remember()`` for local reads;
* use ``$runtime->cache()->set()`` only for local fills;
* call runtime ``invalidateKey()``, ``invalidateTag()``, or
  ``clearNamespace()`` after committed source-data changes;
* run a consumer on every node and node SQLite pruning/checkpoint maintenance
  separately;
* run shared-transport retention pruning from one coordinated scheduler;
* monitor consumer lag, transport errors, cursor age, cache hit rate, and
  retention-prune activity.

During an incident:

* if a node is suspect, ``clearNamespace()`` gives a broad distributed reset;
* if a node was offline longer than retention, allow its consumer recovery to
  clear its local namespace before it resumes;
* if the transport is unavailable, ordinary local reads continue, but remote
  invalidations stop propagating—restore transport and process the backlog;
* do not rely on Cluster Cache alone for an immediate security revoke; enforce
  that decision at the authoritative service or data store.

Troubleshooting
---------------

``A remote node still has an old value``
   Confirm the source change called a *runtime* invalidation method, not
   ``$runtime->cache()->delete()`` or ``invalidateTag()``. Then verify the
   remote node's consumer is running, connected to the same cluster and shared
   transport, and using the expected namespace.

``A node repeatedly clears its cache on startup``
   Its cursor is likely behind event retention, its local SQLite file is being
   discarded, or its node ID changes every deployment. Inspect retention,
   volume persistence, and identity configuration.

``Events are growing without bound``
   Configure transport retention. With the PDO transport, schedule bounded
   ``pruneBefore()`` calls. Do not prune more aggressively than your longest
   supported outage unless frequent full local-cache recovery is acceptable.

``The origin node appears stale after a transactional outbox write``
   The consumer intentionally skips its own event. Ensure the application
   explicitly deletes or invalidates the origin node after the transaction
   commits, as shown above.

``Can multiple clusters share one transport?``
   Yes. Use distinct ``cluster`` names. Events and cursors are partitioned by
   cluster name; capacity, retention, and operational monitoring remain shared
   concerns of the underlying transport.
