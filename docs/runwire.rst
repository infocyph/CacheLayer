=======================
Runwire 2.1 integration
=======================

CacheLayer 4.0 ships an optional integration with Runwire 2.1. Runwire is not a
core dependency: ordinary PHP-FPM, CLI, and other consumers continue to use the
normal CacheLayer paths without installing it.

Install Runwire only in applications that already use it::

   composer require infocyph/runwire:^2.1

Ownership contract
==================

The framework or application owns the Runwire runtime. CacheLayer never starts a
listener, worker pool, supervisor, or event loop because Runwire is installed.

The integration follows four rules:

* share the framework's active RuntimeContext at worker/application bootstrap;
* share the current RequestContext and CoroutineScope only while that request
  or task is executing;
* use supported Runwire capabilities automatically while preserving Runwire's
  worker/event-loop ownership;
* keep the ordinary synchronous CacheLayer path when Runwire is absent,
  inactive, or does not expose the required capability.

Binding a runtime
=================

Bind the concrete context supplied by the host after the worker has been
created. Rebind after a fork, worker replacement, or generation change, and
release the binding during worker/application shutdown.

.. code-block:: php

   use Infocyph\CacheLayer\Integration\Runwire\RunwireIntegration;
   use Infocyph\Runwire\RuntimeContext;

   function bootCacheLayer(RuntimeContext $context): void
   {
       RunwireIntegration::bind($context);
   }

   function shutdownCacheLayer(RuntimeContext $context): void
   {
       RunwireIntegration::release($context);
   }

Installation alone does not create an active binding.

Request and task scope
======================

The host shares the actual request/task scope around application work:

.. code-block:: php

   $result = RunwireIntegration::share(
       $requestContext,
       $scope,
       fn () => $application->handle($request),
   );

When a request context is available, memoize(), object remember(), and once()
use request-owned memoizers. Concurrent requests therefore cannot observe or
flush each other's memoized state. If a persistent concurrent runtime is bound
but no request scope has been shared, these helpers bypass process-global
memoization rather than risk cross-request leakage.

Outside Runwire, or after RunwireIntegration::release(), memoization keeps its
normal process-local behavior.

Cooperative waits
=================

Existing bounded polling waits can use the shared CoroutineScope when the
active runtime exposes RUNWIRE_COROUTINES. CacheLayer does not wrap synchronous
PDO, filesystem, Redis, MongoDB, Memcached, Scylla, or other native client calls
and does not claim that those calls become asynchronous.

Operational failures and cancellation are not retried through a second fallback
path. A mutation or invalidation that may already have completed is never
replayed merely because a Runwire-assisted operation failed.

Worker-owned invalidation
=========================

A Runwire task/service worker may host the durable invalidation consumer after
the host has attached its own event loop:

.. code-block:: php

   use Infocyph\CacheLayer\Integration\Runwire\RunwireWorkerIntegration;

   $task = RunwireWorkerIntegration::startClusterConsumer(
       $workerContext,
       $clusterRuntime,
       batchSize: 1_000,
       idleSeconds: 0.1,
   );

   if ($task === null) {
       $clusterRuntime->consume();
   }

Each consume call remains bounded by batchSize and serial within one cursor
scope. Empty/short batches wait cooperatively. Full batches yield so lifecycle
work cannot be starved. Runwire owns cancellation and drain. An unhandled
consumer failure remains a failed worker task; Runwire's worker/supervisor
policy owns unhealthy-worker stop and restart/backoff behavior.

ClusterRuntime::status() remains the operational source for cursor progress,
pending event count, last consumed count, recovery, and last consume error.

Do not construct backend connections in a prefork master and reuse them in
children. Create the Node Cache and invalidation transport in worker bootstrap
after the fork.

The self-contained examples/runwire-invalidation-worker.php demonstrates the
complete binding, worker-owned loop, graceful stop, and cursor progression. It
uses the SQLite invalidation transport testing mode only so the example can run
without an external service. Production deployments should use an advertised
shared transport such as PostgreSQL/MySQL PDO or Redis/Valkey Streams.

Worker-owned Node maintenance
=============================

Node SQLite maintenance can use the same task/service worker ownership:

.. code-block:: php

   $task = RunwireWorkerIntegration::startNodeMaintenance(
       $workerContext,
       $maintenance,
       intervalSeconds: 60.0,
       pruneLimit: 5_000,
       optimizeEvery: 60,
   );

The runner performs one serial maintenance cycle at a time, so CacheLayer does
not overlap its own prune/checkpoint/optimize work for that worker. pruneLimit
bounds expiry deletion. optimizeEvery set to 0 disables automatic optimize
calls.

SQLite operations are still blocking operations. Run maintenance in a suitable
background worker, choose intervals from observed writer contention, and do not
put full maintenance on the request hot path.

Topology
========

Native prefork supervision and worker replacement require Runwire's PCNTL/POSIX
capabilities. Portable single-process Runwire can execute the normal CacheLayer
APIs, but external supervision owns process restart/replacement. Host-owned
runtimes retain their own workers and event loops.

APCu remains local to one PHP process/SAPI. A dedicated invalidation worker does
not clear unrelated FPM or other worker APCu domains. Every topology claiming
node-wide L1 coherence must run invalidation consumption in each relevant
process domain or disable that L1 assumption.

Shutdown and rollback
=====================

On graceful worker stop, Runwire cancels and drains worker-owned CacheLayer
background tasks. Release the runtime binding when the generation is discarded:

.. code-block:: php

   RunwireIntegration::release($runtimeContext);

To roll back the optional integration, stop the Runwire-owned CacheLayer tasks,
remove the bootstrap/scope binding, and return to explicit
ClusterRuntime::consume() and NodeCacheMaintenance::cycle() calls. No cache
storage format depends on Runwire.

HTTP scheduling
===============

Runwire 2.1 adaptive HTTP scheduling is a host-level concern. CacheLayer does
not select FIXED, LATENCY, THROUGHPUT, or AUTO and does not attribute HTTP
throughput changes to cache storage. Keep Runwire's certified host defaults
unless the application measures and chooses another policy.
