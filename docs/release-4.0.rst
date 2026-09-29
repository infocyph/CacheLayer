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
* Runwire 2.1 remains an optional dependency, but CacheLayer 4.0 ships and
  release-verifies runtime/request scope integration plus host-owned cluster
  invalidation and Node maintenance runners.

Security and storage
====================

* Bounded recursive payload traversal prevents cyclic/deep value exhaustion.
  Accepted nesting depths remain readable inside signed/compressed record envelopes.
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
  New scopes are cold-cleared before runtime exposure. Recreated/restored histories
  require a coordinated cutover to a new, never-used transport identity.
* PSR-6 deferred reads and mutation ordering are coherent before and after
  ``commit()``.
* Numeric-string key/tag identity is preserved through batching and tiering.
* Memcached long TTLs use the correct absolute-expiration conversion.
* Tiered caches fence upper tiers on false returns and exceptions during writes,
  invalidation, and promotion. Reads use the authoritative last tier until a
  successful full clear reconciles every tier.

Runwire 2.1
===========

* The application shares the active Runwire runtime and request/task scope;
  CacheLayer does not discover or create a runtime globally.
* Capability-driven behavior falls back to the normal CacheLayer path before an
  operation starts when Runwire or the needed capability is unavailable.
* Concurrent persistent requests use isolated request-owned memoizers; an
  unscoped concurrent runtime never falls back to process-global memoization.
* Task/service workers can own bounded invalidation consumption and Node SQLite
  maintenance while Runwire retains worker, supervisor, and event-loop ownership.
* Worker cancellation/drain propagates through Runwire. Backend operations stay
  synchronous and no HTTP scheduling or throughput improvement is claimed.
* A dedicated PHP 8.4/8.5 release consumer installs Runwire 2.1 separately and
  executes the shipped invalidation-worker example.

Atomicity and counters
======================

* Redis/Valkey counters use a dedicated keyspace and exact decimal integer
  parsing with PHP range checks.
* Counter clear isolation, TTL, decrement, overflow, and concurrent
  initialization are covered.
* Stale cleanup uses compare-safe backend operations where deletion could race
  a concurrent replacement.
* Tag generation initialization is race-safe across supported backend families.

Release verification
====================

The 4.0 release gate covers:

* PHP 8.4 and 8.5 with stable and lowest supported dependency resolution;
* Linux and Windows core smoke tests plus clean no-dev consumer installs;
* independent PSR-6 and PSR-16 contract consumers;
* documentation builds with warnings treated as errors;
* real Redis Cluster and Scylla CQL release jobs, with real MongoDB exercised
  by the PHPForge service matrix;
* Runwire 2.1 consumer certification and persistent-worker soak coverage across
  PHP 8.4/8.5 stable and lowest dependency sets.

The Runwire certification measures 4,000 cache operations after a separate
250-iteration warmup and validates the expected 3,500 reads plus 500 writes in
both baseline and integrated runs. It is a bounded regression/correctness gate,
not a universal production-throughput claim.

The supported cluster-history reset contract is explicit: recreated, restored,
or ID-reused invalidation histories require a new, never-used
``transportIdentity`` and coordinated reconciliation of every APCu/L1 domain.
Arbitrary same-identity history resets are not supported.

Upgrade
=======

Read :doc:`upgrade-4.0` before deploying over a 3.x installation. The guide
covers runtime requirements, signed record changes, SQL collation, Node
identity, cursor v3, counter migration, coordinated cutover, and rollback.
