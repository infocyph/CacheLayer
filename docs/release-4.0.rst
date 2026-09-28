========================
CacheLayer 4.0.0 release
========================

CacheLayer 4.0.0 is a security, correctness, and contract-focused major
release. It intentionally drops 3.x backward-compatibility requirements where
they conflict with a safer or more coherent design.

Platform
========

* Minimum PHP version is 8.4.
* Release verification targets PHP 8.4 and 8.5.
* Runwire 2.1 integration remains optional and is not part of the 4.0 core
  runtime contract.

Security and storage
====================

* Bounded recursive payload traversal prevents cyclic/deep value exhaustion.
* Signed cache records authenticate logical storage identity and key.
* Object and Closure deserialization is opt-in instead of enabled by default.
* Filesystem paths validate symlink/trust boundaries across file-backed owners.
* Redis, PDO, MongoDB, integrity, and signing secrets are redacted from failure
  paths.
* MySQL/MariaDB logical identity columns use byte-sensitive collation.

Correctness
===========

* Node SQLite transactions no longer roll back caller-owned transactions.
* Node L1 identity includes the SQLite store and stale L1 failures are fenced.
* PDO invalidation publication uses commit-safe cluster-scoped ordering.
* Cluster cursors are scoped by cluster, node, namespace, and transport identity.
* PSR-6 deferred reads and mutation ordering are coherent before and after
  ``commit()``.
* Numeric-string key/tag identity is preserved through batching and tiering.
* Memcached long TTLs use the correct absolute-expiration conversion.
* Tiered caches invalidate/fence skipped L1 state rather than serving stale data.

Atomicity and counters
======================

* Redis/Valkey counters use a dedicated keyspace and exact decimal integer
  parsing with PHP range checks.
* Counter clear isolation, TTL, decrement, overflow, and concurrent
  initialization are covered.
* Stale cleanup uses compare-safe backend operations where deletion could race
  a concurrent replacement.
* Tag generation initialization is race-safe across supported backend families.

Upgrade
=======

Read :doc:`upgrade-4.0` before deploying over a 3.x installation. The guide
covers runtime requirements, signed record changes, SQL collation, Node
identity, cursor v3, counter migration, coordinated cutover, and rollback.
