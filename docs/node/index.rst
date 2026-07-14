=====================
Node Cache
=====================

Node Cache is CacheLayer's opinionated cache for a single application server:
optional APCu provides a hot L1 and a local SQLite database provides durable L2
storage. It returns the normal ``Cache`` facade, so application code keeps the
same PSR-6, PSR-16, tagging, and ``remember()`` APIs.

Use these focused pages in order:

.. toctree::
   :maxdepth: 1

   overview
   operations
   production

For cross-server invalidation, continue with :doc:`/cluster/index`.
