.. _adapters.shared_memory:

========================================
Shared Memory Adapter (``sharedMemory``)
========================================

Factory: ``Cache::sharedMemory(string $namespace = 'default', int $segmentSize = 16777216)``

Requirements:

* ``ext-sysvshm``

Highlights:

* values shared across PHP processes on the same host
* namespace-specific segment key strategy
* shared locks for reads and exclusive locks for mutation
* atomic coordination via ``Cache::atomic()`` using the existing exclusive
  cross-process lock around the complete shared store mutation
* tag metadata and values share the same locked store, so stale-tag checks can
  be completed inside the same critical section
* an owner marker that rejects accidental ``ftok`` segment collisions
* good for host-local IPC cache use cases

Notes:

* atomicity is host-local; it does not coordinate separate machines
* data is not portable across hosts
* capacity is limited by the shared memory segment size
* use dedicated, untagged keys for replay/state coordination even though stale
  tagged records are recognized inside the shared-memory lock

Example
-------

.. code-block:: php

   use Infocyph\CacheLayer\Cache\Cache;

   $cache = Cache::sharedMemory('worker-bus', 8 * 1024 * 1024);
   $cache->set('heartbeat.worker-1', time(), 15);

   $atomic = $cache->atomic();
   $claimed = $atomic?->setIfAbsent('worker.claim.1', true, 15);
