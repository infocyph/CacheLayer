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
* good for host-local IPC cache use cases

Notes:

* data is not portable across hosts
* capacity limited by shared memory segment size

Example
-------

.. code-block:: php

   use Infocyph\CacheLayer\Cache\Cache;

   $cache = Cache::sharedMemory('worker-bus', 8 * 1024 * 1024);
   $cache->set('heartbeat:worker-1', time(), 15);
