Upgrading from 3.x to 4.0
=========================

CacheLayer 4.0 is an intentional breaking release with a minimum runtime of
PHP 8.4. It does not preserve 3.x API shape, named parameters, defaults,
storage formats, schemas, or behavioral contracts merely for backward
compatibility.

Use a coordinated cutover. Do not run mixed 3.x and 4.0 writers against the
same mutable cache state unless that exact combination has been proven safe.
For disposable cache values, a cold 4.0 namespace is usually the cleanest
upgrade path. Preserve and explicitly migrate durable coordination state.

Runtime floor
=============

CacheLayer 4.0 requires PHP 8.4 or newer. Upgrade the application runtime before
installing the package. The release gates cover PHP 8.4 and PHP 8.5.

Serialization and authenticated records
========================================

4.0 disables object and Closure deserialization by default. Enable either only
through an explicit ``CacheOptions`` policy after reviewing the trust boundary.

When ``integrityKey`` is configured, signed records use the 4.0 authenticated
envelope. The signature binds the record purpose, logical storage identity,
and logical cache key. A signed blob copied to a different key or namespace is
rejected. Legacy unbound signed records are not silently accepted.

For a coordinated upgrade:

* stop 3.x writers before enabling 4.0 writers for the same logical store;
* cold-clear disposable cached payloads when record compatibility is uncertain;
* keep integrity keys in secret storage and out of exception messages, logs,
  DSNs, and configuration dumps;
* do not re-enable object or Closure deserialization solely to keep old cache
  entries readable.

Node Cache identity and policy
==============================

Node Cache derives its L1 and lock identity from the SQLite store plus
namespace. Two SQLite stores using the same namespace therefore do not share
an APCu or lock identity accidentally.

``NodeCacheConfig`` carries one cohesive ``CacheOptions`` policy. Review Node
construction code that previously relied on independent defaults. L1 mutation
failures are fenced so an old promoted value cannot override authoritative
SQLite after a lower-tier update.

APCu remains process/SAPI local. A CLI invalidation consumer does not clear
unrelated FPM or worker APCu domains. Every advertised topology needs its own
consumer lifecycle or an explicit topology constraint.

SQL identity collation
======================

MySQL and MariaDB cache and invalidation identity columns are byte-sensitive in
4.0 using ``ascii_bin``. New tables are created with the correct collation.
Existing tables are metadata-checked and altered only when required.

Before the first 4.0 process uses an existing SQL store:

1. back up durable invalidation and event state;
2. inspect identity columns and application namespaces for case-folded data;
3. stop mixed-version writers;
4. allow the 4.0 schema installer to harden the identity columns, or perform
   the equivalent reviewed migration during a maintenance window;
5. verify case-distinct namespaces and keys after migration.

Disposable cache rows may be dropped and rebuilt. Do not treat invalidation
history, authorization/replay state, or other durable coordination data as
ordinary cache rows.

Cluster cursor v3
=================

Cluster cursor identity is scoped by cluster, node, namespace, and transport
identity. Legacy ``(cluster,node)`` and intermediate
``(cluster,node,namespace)`` cursors are not copied into the new scope.

When an old cursor format is detected, CacheLayer clears the affected local
namespace before establishing new progress. This trades cache warmth for proof
that an old shared cursor cannot skip invalidations.

Keep transport retention long enough for deployment and outage windows. After
cutover, verify every node has a stable node ID, namespace, transport identity,
and its own consumer.

Atomic counter keyspace
=======================

Redis and Valkey counters live under
``cachelayer:counter:<namespace>:<key>``. Ordinary cache ``clear()`` operations
do not touch this keyspace.

If 3.x counters carry security-relevant windows, quotas, replay attempts, or
rate limits, migrate them deliberately while 3.x writers are stopped. Do not
delete old counters until the application has copied the required state or
intentionally allowed the old windows to expire.

4.0 validates exact decimal counter results against the PHP integer range.
Malformed and out-of-range stored values fail closed instead of saturating.

PSR and cache behavior changes
==============================

4.0 corrects several observable behaviors:

* PSR-6 deferred values are visible through reads before ``commit()``;
* immediate save, delete, and clear operations reconcile queued deferred state
  instead of allowing a later commit to resurrect an older value;
* numeric-string keys and tags preserve logical identity through batching and
  tier promotion;
* tiered caches that skip L1 write-through invalidate or fence stale L1 state;
* Memcached relative TTLs longer than 30 days are converted to Memcached's
  absolute-expiration form;
* direct PSR-6 pools validate public keys consistently and missing deletes
  succeed where the PSR contract requires it.

Durable invalidation
====================

PDO invalidation publication is commit-safe within a cluster scope. Event IDs
are allocated while holding the cluster publication lock so commit reordering
cannot make a later event permanently hide an earlier one.

Cursor state is scoped by cluster, node, namespace, and transport identity.
Retention gaps trigger a local namespace clear before progress is advanced.
Permanent poison events are not skipped silently.

Rollback
========

Rollback means restoring the complete previous release and its compatible
storage configuration, not merely changing the Composer version.

Before deployment record:

* the exact 4.0 commit or tag;
* PHP and extension versions;
* database/cache backend versions;
* schema and cursor versions;
* namespace and transport identities;
* any migrated durable counter or invalidation state.

If rollback is required, stop 4.0 writers first. Restore the previous release
with storage it can safely interpret. Do not point 3.x readers at 4.0
authenticated records or cursor state and assume compatibility.
