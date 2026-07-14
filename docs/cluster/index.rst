=========================
Cluster Cache
=========================

Cluster Cache distributes durable invalidation events between independent Node
Cache instances. It never replicates values or local cache files: reads and
writes stay on the current node, while key, tag, and namespace invalidations
are replayed by consumers on other nodes.

Use the pages below to choose a transport, bootstrap every node, operate the
consumer, and plan recovery and retention.

.. toctree::
   :maxdepth: 1

   overview
   topology
   operations
   reliability

Cluster Cache builds on :doc:`/node/index`.
