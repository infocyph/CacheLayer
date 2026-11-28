==========================
CacheLayer 4.2 release notes
==========================

The planned 4.2 minor release expands atomic cache capabilities and hardens
failure recovery while retaining default backend modes.

Atomic coordination
===================

* All three PDO atomic operations recover from classified transaction aborts
  and contention: MySQL/MariaDB deadlocks and lock wait timeouts, PostgreSQL
  serialization failures and deadlocks, and recognized SQLite BUSY errors.
* Retries are bounded to eight attempts with jitter and a five-second retry
  scheduling budget. The budget does not interrupt an in-flight database call.
  Unknown outcomes are not replayed.
* APCu supports opt-in local arbitrary-value coordination with
  ``atomicMode: true``; default operation remains lock-free.
* ScyllaDB/Cassandra supports opt-in native lightweight transactions with
  ``atomicMode: true`` in a dedicated, untagged namespace.
* Tiered caches support delegation to one atomic-capable pool or an
  atomic-capable final pool with ``authoritativeOnly: true``.
* Node caches support native SQLite atomic operations in SQLite-only mode
  (``apcuEnabled: false``).

Correctness and deployment
==========================

* PDO locks reject same-session reentry and verify server-side ownership.
* MySQL/MariaDB schema installation requires InnoDB and no longer silently
  accepts non-transactional cache tables.
* Redis, Valkey, Redis Cluster and Memcached distinguish invalid atomic
  responses and backend failures from expected conditional misses.
* MongoDB atomic inserts and replacements require confirmed write outcomes.
* Ordinary bulk mutations and cluster publication are not automatically
  replayed after uncertain or partial success.

Migration
=========

Read :doc:`upgrade-4.0` before deployment, especially for existing
MySQL/MariaDB tables and dedicated atomic namespaces. New atomic modes are
opt-in; do not mix coordination modes on the same namespace. Keep
security-sensitive state on an appropriate authoritative backend with
``failOpen: false``.

Verification
============

PR #37 passed the hosted PHP 8.4, 8.5 and 8.6 QA and analyzer matrix,
benchmarks, PSR contracts, documentation build, and release verification.
Real Redis Cluster and ScyllaDB CQL/LWT smoke tests passed; native ScyllaDB
CQL verification covers PHP 8.4 and 8.5 pending an upstream PHP 8.6 driver
binary. The original historical PostgreSQL advisory-lock failure was not
independently reproduced, but the new regression suite passed.
