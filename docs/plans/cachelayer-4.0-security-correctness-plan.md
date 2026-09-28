# CacheLayer security, correctness, and release plan

Date: 2026-09-28  
Status: Implementation in progress; Batches 1-3 complete; Batch 4 next\
Audited revision: `b064b8196ddc4672ce37be252bc7a4cadb78527e` (local tag `3.4`)  
Release target: **4.0.0 — next major release**

## Implementation tracker

Updated: 2026-09-28  
Working branch: `feature/improvements`  
Draft PR: [#29 — CacheLayer 4.0 security and correctness hardening](https://github.com/infocyph/CacheLayer/pull/29)

| Batch | Findings | Status | Current gate |
| --- | --- | --- | --- |
| 1 — Security and transaction containment | R01, R03, R04, R05, R09, R10 | **Complete** | Implemented and verified on exact commit `5a9f4bd553b4d97cca72d05affa32b6e7ce3c37e`; Security & Standards run #173 passed. |
| 2 — Authenticated payload/storage identity | R02, R15, R18 | **Complete** | Implemented and verified on exact commit `1924a74da3b9d6474696631405e839bd52ec158b`; Security & Standards run #210 passed. |
| 3 — Durable invalidation protocol | R06, R07 | **Complete** | Implemented and verified on exact commit `3046e91fdc64bd2f1c9e8bd56a6d3dfc96057b7e`; Security & Standards run #240 passed. |
| 4 — Cache contracts and memoization | R08, R11, R12, R13, R16, R17 | **In progress** | R08/R11/R12/R13/R16/R17 implementation is active; current QA run #266 exposed deferred/bulk, Memcached TTL, static-analysis, formatting, and Rector regressions that are being resolved before closure. |
| 5 — Counters and backend races | R14 plus race review | Not started | Pending prior batches. |
| 6 — Release gates and integration | R19 plus release acceptance / optional Runwire 2.1 | Not started | Final full-matrix and packaging gate. |

### Batch 1 tracker

| Finding | Implementation | Regression evidence | QA state |
| --- | --- | --- | --- |
| R01 — bounded recursive traversal | Complete | Added bounded subprocess coverage for direct/mutual cycles, deep input, signed/unsigned/compressed records; shared traversal guard also protects callable fingerprints | Passed final Batch 1 QA. |
| R03 — immutable adapter policy | Complete | Shared-adapter conflicting-policy regression added | Passed final Batch 1 QA. |
| R04 — atomic file consume deletion | Complete | File and PHP-files consume failure coverage added with warning-free deterministic failure handling | Passed final Batch 1 QA. |
| R05 — Node SQLite transaction ownership | Complete | Caller-owned transaction preservation regression added | Passed final Batch 1 QA. |
| R09 — filesystem trust boundaries | Complete for Batch 1 scope across File/PHP-files roots and locks, Node/PDO SQLite paths, FileLockProvider, and shared-memory token paths | Symlink-root regression added; filesystem owners were audited beyond the initially reproduced PHP-files path | Passed final Batch 1 QA; broader cross-platform release coverage remains under release acceptance. |
| R10 — secret redaction | Complete for Redis/Valkey DSNs, PDO credentials/DSNs, MongoDB URI creation, integrity/signing keys, and tier descriptors | Error/trace and `#[SensitiveParameter]` regression coverage added | Passed final Batch 1 QA. |

**Batch 1 closure evidence:** exact commit `5a9f4bd553b4d97cca72d05affa32b6e7ce3c37e` passed Security & Standards run #173: clean install, PHP 8.4/8.5 analysis, PHP 8.4/8.5 benchmarks, and all four stable/lowest QA jobs. During Batch 1 QA the inherited skip-directive and reference-integrity failures were resolved without weakening PHPForge gates.


### Batch 2 tracker

| Finding | Implementation | Regression evidence | QA state |
| --- | --- | --- | --- |
| R02 — authenticated payload identity | **Complete** | Bound `cache-record:v3` HMAC envelopes authenticate purpose, logical storage identity, and key. Cross-key/cross-namespace substitution, legacy unbound signed payloads, malformed/wrong-key payloads, tier promotion, secure defaults, and adapter/atomic verification paths have regression coverage. | Passed final Batch 2 QA on run #210. |
| R15 — Node storage/topology identity | **Complete** | Node APCu and lock identities include the SQLite store; `NodeCacheConfig` carries one cohesive `CacheOptions` policy; failed L1 mutations fence that L1 from later reads so stale promoted state cannot override authoritative SQLite. Batch 3 additionally established full invalidation cursor scope across cluster, node, namespace, and transport identity. Separate PHP SAPIs/processes still require their own consumer lifecycle; no cross-process APCu coherence is implied. | Core identity/L1 behavior passed Batch 2 run #210; topology follow-through passed Batch 3 run #240. |
| R18 — SQL binary identity | **Complete** | New MySQL/MariaDB cache and invalidation schemas create identity columns as `ascii_bin`; existing schemas are metadata-checked and hardened only when required, avoiding repeated identity `ALTER TABLE` work. Backend regressions verify byte-sensitive identity and case-distinct namespaces/keys. | Passed final Batch 2 QA on run #210. Consolidated upgrade/rollback instructions remain a Batch 6 release-documentation gate. |

**Batch 2 implementation/closure commits:** `df108d47309b84c9334b5747e08172b03460933b` (contracts), `a0da9349ecffe94d3e4491c8ca149fe37b31d710` (Node identity/topology), `0f9cce33e2763910b637709e534373f634af9d75` (logical storage identity propagation), `47e0f05cf0245f7d69bbc12a892f3ddbcc81ea99` (bound signed envelope and secure serialization defaults), `3ee8e84ca21ce2eac63a0b1552750ef83beb7556`, `edef15420895cee62b179c7fa9d6513479b3b336`, `045d5ff89949370744f6d95646165a9b11efd721`, `f2c3e55db67ecc3237dc87494c2828c66f33d5ee`, and `7e4a26f9f3c9e0ae4902229edc68a4429fbdaef0` (adapter/atomic identity verification and codec hardening), `8b2dbfffa7805e8bcd8b31f4ee527ba20ef91b01` and `62f984c13c342c9faa37400cd4a6a262c3f627a3` (SQL/shared-memory identity), `64fc9ba5fcdfceb12e92efd2912192486e0a1cd6` through `1924a74da3b9d6474696631405e839bd52ec158b` (final schema idempotence, unified Node policy, L1 coherence fencing, regressions/docs, and exact PHPForge formatting).

**Batch 2 closure evidence:** exact commit `1924a74da3b9d6474696631405e839bd52ec158b` passed Security & Standards run #210: clean install, PHP 8.4/8.5 analysis, PHP 8.4/8.5 benchmarks, and all four stable/lowest QA jobs. Pest, Pint, PHPCS, Deptrac, Rector, skip-directive, reference-integrity, duplicate-code, and comment-policy gates all passed.

### Batch 3 tracker

| Finding | Implementation | Regression evidence | QA state |
| --- | --- | --- | --- |
| R06 — durable PDO invalidation ordering | **Complete** | PDO publication acquires a per-cluster transactional lock before event-ID allocation, so later publishers cannot commit a higher ID ahead of an earlier same-cluster transaction. Real MySQL/PostgreSQL multi-process coverage verifies blocked reversed-order publication, rollback, publisher process death, and subsequent ordered replay. Different clusters retain independent lock domains. | Passed final Batch 3 QA on run #240. |
| R07 — full cursor-scope ownership | **Complete** | SQLite cursor storage is versioned as v3 and keyed by cluster + node + namespace + transport identity. Regressions cover same-node multi-namespace isolation, independent transport histories with overlapping event IDs, restart persistence, reset behavior, retention recovery, and legacy `(cluster,node)` plus intermediate v2 `(cluster,node,namespace)` migration. Legacy progress is never copied into the new scope; affected local cache state is cleared before new progress is established. | Passed final Batch 3 QA on run #240. |

**Batch 3 implementation/closure commits:** `965d90ea9fbc6e39f77988e3aef4900a792b30c9` through `6a658362f6dc777f1f0f50a196289d7ef1ef26c0`, plus `2d915a90e22937e8dc68a48826ae40599e9f66f3`, `831229c22a9f24f59ac2814e7a0476c55fa615f6`, and `c5bc44c50b507ed8fd3d3d28be2ce6915e9b8352` (initial scoped-cursor recovery), `4b885a133b8ec08dd0ed855e502a708f705e27fc`, `fa131a8278294dba3e290c761bf017bb7e2cd5ed`, `ffcaec51f83b9a9d2a9dfbffbdb41e57f9ad019a`, `2b2ac8e04a1168204b397f25555e4f5221b0f5a8`, `63d8fe94ec7df44c5238c527116945fea3a6a2bb`, `13e6ec0e13395b3f6e4fdedf6e04a05480d75474`, `9b95a3ec8ecea5dd5da4e87f9ce1a9b7a3b23d6a`, and `4b6066dc530369b8b3dcc77088919091de6173d6` (R06 publication locking, transaction ownership, real MySQL/PostgreSQL process isolation, and QA cleanup), `5c3410c4755f1ce224cb87f0c421b729cfc5aaf7`, `e28335cd8776addeb42aae669a4901fc3f5826cf`, `d40a0d27db9eaf514ee12d2747a170252a7f179e`, `f6e89c6e15cc91be73d2f8f276f4dbf18ecf1072`, `ff90d2fdf7b7893ec18738567f4314d87bce76f2`, `a8ef884bcd738421ab0bfb80a108984497911d30`, `2917d45b7db368a53aedeb4eb60c82c0c9857174`, `dedf45b1c0698c7cdd0a69f4495a12d895764fce`, and `3046e91fdc64bd2f1c9e8bd56a6d3dfc96057b7e` (transport-aware v3 cursor scope, safe legacy/v2 recovery, restart/reset tests, docs, and exact PHPForge ordering).

**Batch 3 closure evidence:** exact commit `3046e91fdc64bd2f1c9e8bd56a6d3dfc96057b7e` passed Security & Standards run #240: clean install, PHP 8.4/8.5 analysis, PHP 8.4/8.5 benchmarks, and all four stable/lowest QA jobs. The run includes real MySQL/PostgreSQL concurrent publication tests, cursor-scope/migration regressions, Pest, Pint, PHPCS, Deptrac, Rector, skip-directive, reference-integrity, duplicate-code, and comment-policy gates. All PR code-scanning review threads were resolved on the verified head.


### Batch 4 tracker

| Finding | Implementation | Regression evidence | QA state |
| --- | --- | --- | --- |
| R08 — memoizer identity | **Implemented; QA pending** | Callable/value fingerprints are type-tagged; same-line closure identity and lifetime-safe object identity coverage added. | Current Batch 4 gate still failing elsewhere. |
| R11 — deferred PSR-6 lifecycle | **Implemented; QA fixes in progress** | Pending reads, snapshot semantics, overwrite/delete/clear ordering, commit retry/finalization paths are being consolidated at the pool boundary. | Run #266 exposed Node bulk-copy and stale legacy expectation failures. |
| R12 — numeric-string keys/tags | **Implemented broadly; QA fixes in progress** | Logical keys/tags are normalized through batch/tag paths instead of being rejected solely because PHP coerces numeric-string array keys. | Run #266 exposed remaining typing/legacy-test cleanup. |
| R13 — skipped-L1 coherence | **Implemented; QA pending** | Tier writes with disabled L1 write-through invalidate/fence upper-tier state; single and bulk regressions added. | Static complexity cleanup still required. |
| R16 — Memcached >30-day TTL | **Implemented; QA fix in progress** | Shared expiration conversion covers atomic and lease paths with long-TTL regressions. | Run #266 exposed one missed ordinary single-save conversion. |
| R17 — direct PSR contracts | **Implemented broadly; QA fixes in progress** | Direct key validation, deferred visibility, missing-delete and expiration contracts are being aligned across adapters. | Full common-contract gate pending. |

**Batch 4 current QA evidence:** Security & Standards run #266 reached 296 passing Pest tests but failed seven regressions plus PHPStan/Pint/Rector. These failures are treated as open Batch 4 work; the batch is not closed until an exact-head full gate passes.

## Decision

The library needs changes before another release can be called ready. The audit reproduced security-sensitive failures, incorrect cache results, transaction data loss, and release-gate failures. Existing tests and clean static/security analysis do not cover these cases.

Target **4.0.0** for the complete plan, as explicitly selected by the maintainer. CacheLayer 4.0 has **no backward-compatibility preservation requirement with 3.x**: public API shape, named parameters, defaults, storage formats, schemas, and behavioral contracts may change when a cleaner, safer, or more coherent design results. Patch backports and an alternative minor release are outside this plan. Avoid unrelated rewrites, but do not retain legacy contracts solely for BC.

Persisted-state transitions still require explicit migration/upgrade notes where operators could otherwise lose or misinterpret stored data. Mixed-version compatibility is not a release requirement; coordinated cutover or cold-cache migration is acceptable when it produces the stronger design. Keep PHP 8.3 support unless a separate, justified compatibility decision changes it; add real PHP 8.3 coverage. A PHP floor increase is not required by these fixes. Preserve PSR contracts where required by the interfaces themselves, not for 3.x compatibility.

Track Runwire 2.1 integration as an optional target for 4.0. When Runwire is loaded as the active runtime, CacheLayer automatically uses the relevant available capabilities; otherwise it uses the normal execution path. An executable invalidation-worker example demonstrates this behavior if the workstream is selected for implementation. It is optional both as release scope and as a consumer dependency: deferring the entire workstream does not block 4.0.0. Retain PHP 8.3 support in the core. Any shipped integration requires a demonstrated need and the conditional gates below.

This document follows [PHPForge engineering principles](../../vendor/infocyph/phpforge/resources/engineering-principles.md) and the applicable [PHPForge AGENTS.md workflow](../../vendor/infocyph/phpforge/resources/AGENTS.md).

## Scope and evidence boundaries

The review covered the 101 production PHP files by subsystem, the 32 test/support files, six benchmark files, public documentation, Composer configuration, and the CI wrapper. Areas reviewed include all adapter families, PSR-6/16 facade behavior, atomics, locks, serialization, counters, memoizers, Node Cache, Cluster Cache, invalidation transports, cursors, maintenance, and metrics. The existing graphify graph was used for orientation; findings were checked against current source and probes.

The pre-existing `composer.json` edit changing `mongodb/mongodb` from `^1.20 || ^2.0` to `*` was preserved. No production code, test code, dependency versions, or release tags were changed by this audit.

Evidence labels:

- **Reproduced:** an isolated probe executed the library behavior locally.
- **Protocol reproduced:** the database behavior was reproduced with the SQL pattern used by the implementation; this is not a complete PHP integration test.
- **Source finding:** the code path is identified, but its affected production backend or race has not been exercised here.

Security impact depends on the stated preconditions. This audit does not claim remote code execution without backend/filesystem access, a vulnerable application integration, or another specified trust-boundary violation. It is not a guarantee that every possible defect has been discovered.

## Baseline checks

| Check | Result and meaning |
| --- | --- |
| Normal host | PHP 8.5.4 CLI; Composer 2.10.3 |
| `composer ic:doctor` | Two warnings: missing `pdo_mysql` and `pdo_pgsql`; inherited runtime matrix 8.4/8.5 |
| `composer ic:list-config`, `composer ic:active-config` | Tool configuration resolves from installed PHPForge |
| `composer ic:tests:details` | **Failed overall** |
| Normal-host Pest within that run | **208 passed, 9 skipped, 645 assertions** |
| Skip-directive detector | **24 findings**: 8 PHPUnit and 16 Pest directives; these are separate from the nine runtime skips |
| Reference detector | **10 findings**: nine Cassandra-related references and one test-helper PSR-4 mismatch |
| Syntax, Pint, PHPCS, PHPStan, Psalm, Rector dry run, comment detector | Passed in the installed configuration |
| Duplicate detector | Passed its current gate, but reported **21 clone groups / 946 duplicated lines / 7.00%** |
| Deptrac | Zero violations, **677 uncovered dependencies**; passing this configuration does not establish meaningful package boundaries |
| `composer validate --strict`, `composer check-platform-reqs` | Passed; this does not prove optional backend prerequisites are available |
| `composer audit --locked --format=json` | Zero advisories; abandoned `doctrine/annotations` through development dependency `phpbench/phpbench` |
| `composer ic:release:constraints`, `composer ic:release:audit` | Passed; PHPForge reports abandonment as a non-blocking warning |
| Prepared Redis/Memcached/APCu test subset | **43 passed, 99 assertions**, using host PHP with `apc.enable_cli=1` and disposable services |
| Backend probes | Redis 8.10, Memcached 1.6.45, PostgreSQL 18 images already on the machine; all temporary containers stopped and removed |

The prepared subset used:

```sh
IC_REDIS_HOST=127.0.0.1 IC_REDIS_PORT=<temporary-port> \
IC_MEMCACHED_HOST=127.0.0.1 IC_MEMCACHED_PORT=<temporary-port> \
php -d apc.enable_cli=1 vendor/bin/pest \
  --configuration vendor/infocyph/phpforge/resources/pest.xml \
  --bootstrap vendor/autoload.php \
  tests/Cache/RedisCachePoolTest.php \
  tests/Cache/MemcachedCachePoolTest.php \
  tests/Cache/ApcuCachePoolTest.php
```

An initial direct Pest attempt without the bundled configuration failed with `Could not read XML from file "--cache-directory"`; the explicit configuration above resolved it. The sandbox could not start (`bubblewrap: mountinfo path is not absolute`), so approved host execution was used. These were tooling issues, not library test failures.

No complete MySQL/MariaDB, MongoDB, Scylla CQL, Redis Cluster, Windows, PHP 8.3/8.4, clean production consumer, documentation build, sustained-RPM benchmark, or worker soak gate passed during this audit. `sphinx-build` was unavailable. PostgreSQL testing below exercised transaction ordering through `psql`, not the library's PDO driver. Current CI on a future final revision remains required.

## Required findings

P1 means fix before publishing the complete release because security, isolation, data integrity, or durable delivery is affected. P2 means a required correctness or reliability fix. These are engineering priorities, not CVSS scores.

### R01 — P1: recursive values can terminate the worker

**Reproduced.** `CachePayloadCodec::assertNativeValueSupported()` and `containsUnsupportedDecodedValue()` recurse through arrays without cycle detection or a traversal budget (`src/Cache/Adapter/CachePayloadCodec.php:114,144`). Native unserialization's depth limit does not prevent a shallow reference cycle from being traversed forever afterward. `CallableFingerprint::values()` has the same unbounded traversal pattern.

A 156-byte native record containing a self-reference exhausted a 32 MB PHP process with `allowObjects=false`, `allowClosures=false`, and `maxPayloadBytes=1024`. Encoding the recursive value also exhausted memory. The decode precondition is a writable unsigned backend, or a trusted writer that can generate such data; integrity verification prevents an unsigned attacker from reaching decoding when signing is enabled.

**Change:** make value traversal cycle-safe and bounded before recursive normalization. Preserve lossless supported values where practical; otherwise reject safely before process exhaustion. Apply the same invariant to fingerprint normalization. Keep byte limits and decompression limits; they solve different problems.

**Acceptance:** isolated subprocess tests for direct/mutual references, very deep arrays, repeated references, and signed/unsigned/compressed records complete within explicit time and memory bounds. Ordinary nested data retains its exact type/value. No fatal error or unbounded output is acceptable.

### R02 — P1: signatures do not bind values to their cache identity

**Reproduced.** `CachePayloadCodec::attachSignature()` authenticates payload bytes, but the record contains neither the logical key nor the logical namespace (`CachePayloadCodec.php:77,133`; `AbstractCacheAdapter.php:153`). Copying Alice's signed SQLite payload onto Bob's row returned Alice's `['role' => 'admin']` under Bob's key while `hasPayloadIntegrity()` returned true.

The attack requires backend write access and access to a valid payload signed with the same secret. This is payload substitution, not HMAC forgery. Signing also does not prevent replay of an older valid value under the same key.

**Change:** introduce a versioned authenticated envelope bound to an unambiguous logical namespace/key and format purpose. Validate identity before value deserialization. Use logical identity that survives legitimate tier promotion. Define the authentication-state contract separately from ordinary cache integrity; do not imply replay resistance from HMAC alone.

**Acceptance:** cross-key, cross-namespace, and cross-purpose copies are rejected; legitimate same-key tier promotion works. Wrong-key, unsigned, malformed, compressed, expired, and old-format cases have explicit behavior. Legacy unbound signed payloads must not silently satisfy the new bound-integrity contract. Document cold-cache migration and coordinated rollout.

### R03 — P1: adapter reuse can silently replace a facade's security policy

**Reproduced.** `AbstractCacheAdapter::configureOptions()` freezes policy only once the codec exists (`:50`). Constructing a strict, signed facade and then another facade over the same unused adapter replaces its options. The first facade still advertises payload integrity and accepts objects despite `allowObjects=false` in its own options.

**Change:** establish immutable effective policy at initial binding, or reject conflicting adapter reuse before either facade is operational. Propagate this rule through tiered and Node adapters. Ensure capabilities report the policy actually enforced by storage; review weak-reference paths that do not instantiate the codec.

**Acceptance:** two facades sharing an adapter cannot disagree about integrity, serialization policy, or backend behavior. Equal configurations remain usable if intentionally supported. Test configuration before first use and after scalar/object/weak-reference operations.

### R04 — P1: file consume returns the value even when deletion fails

**Reproduced for File; same code defect in PHP-files.** Both `atomicGetAndDelete()` methods ignore `deleteItemUnlocked()`'s boolean result (`FileCacheAdapter.php:51`; `PhpFilesCacheAdapter.php:50`). With a readable but non-writable data directory, two consumes returned `"usable"`, including with `failOpen=false`.

**Change:** return a consumed value only after successful deletion while holding the key lock. Report backend failure through the configured policy. Audit other atomic mutations for unchecked write/delete results and incomplete writes.

**Acceptance:** filesystem permission failure, failed unlink, disk-full/partial writes, and competing processes cannot yield multiple successful consumptions. Strict mode throws the backend exception; fail-open returns the defined miss/failure result, never an unconsumed value. Cover File and PHP-files independently.

### R05 — P1: Node SQLite rolls back caller-owned transactions

**Reproduced.** `NodeSqliteCacheAdapter::saveMany()` unconditionally starts a transaction and catches the already-active-transaction error by calling `rollBack()` (`:302`). The rollback helper rolls back any active transaction. A business insert made before `saveItems()` disappeared and `inTransaction()` became false. `deleteItems()` also rolls back on error without establishing ownership.

**Change:** track transaction ownership explicitly. Prefer rejecting a caller-owned transaction before mutation, consistently with `PdoAtomicOperations`, unless savepoint participation has a concrete contract. Never commit or roll back work owned by the caller.

**Acceptance:** a failed cache operation preserves the caller's transaction and business rows; independently owned cache transactions commit or roll back correctly. Include errors during batch preparation, execution, deletion, and commit.

### R06 — P1: SQL outbox consumers can permanently miss late commits

**Protocol reproduced on PostgreSQL.** `PdoInvalidationSchema` allocates `BIGSERIAL`/auto-increment IDs on insert. `PdoInvalidationTransport::consumeSql()` subsequently selects only `event_id > cursor` (`:149`). Transaction A allocated ID 1 and remained open; B allocated and committed ID 2; the consumer observed 2 and advanced; A committed, and the next query could never return 1.

**Change:** make the publication/cursor protocol safe under commit reordering. Choose and document a concrete protocol: for example, per-cluster transactional serialization before allocation, or committed outbox relay into an ordered delivery log. Compare lock duration, crash recovery, idempotency, and sustained throughput before selecting. A larger integer, a fixed delay, or a fixed overlap window does not establish correctness.

**Acceptance:** real PostgreSQL and MySQL tests with at least two independent connections cover reversed commit order, rollback, long-running transactions, process death, duplicate replay, retention, and consumer restart. No committed invalidation may be skipped. Provide schema, rollout, rollback, and mixed-version rules.

### R07 — P1: cursor storage collides across namespaces on one node

**Reproduced.** `SqliteCursorStore` keys cursors by `(cluster_name, node_id)` only (`:71,96`), while `InvalidationHandler` applies a single namespace. Two runtimes using the same SQLite file, node ID, and cluster but namespaces A/B produced `[A consumed=1, B consumed=0, B value="stale"]` for an invalidation addressed to B.

**Change:** include the complete consumption scope in cursor identity, or use one consumer that handles every namespace before advancing a shared cursor. Document whether multiple concurrent consumers may share a scope and enforce the chosen model. Include recovery and administrative skip operations in that scope.

**Acceptance:** independent namespaces, nodes, clusters, and transport identities cannot advance each other's progress. Test migration of existing cursor rows without skipping invalidations, concurrent consume, reset, restart, and lost/truncated history.

### R08 — P1: memoizer identities can return another input's result

**Reproduced.** `CallableFingerprint::closure()` hashes file/line/captures, which collides for distinct closures on the same source line (`:63`). Two functions returning A/B produced A/A. `value()` converts objects into ordinary strings (`:52`); an object and the matching literal string both returned the object's result. `OnceMemoizer` retains `spl_object_id()` in cache keys after the object dies (`:50,65`); a new object reusing the ID returned the previous object's `"alice"` instead of `"bob"`.

**Change:** make normalized values explicitly type-tagged and closure/caller identity unambiguous. Use lifetime-safe weak identity tracking where object identity is intended. Retain a documented useful call-site contract for `once()`; do not accidentally turn it into a never-hitting cache. Bound normalization and retained state, and preserve `flush_memoizers()` as the request-boundary reset.

**Acceptance:** same-line closures, object/string/resource lookalikes, reused object IDs, bound and static callbacks, reference captures, cyclic input, null results, and worker request resets remain isolated. Test collecting owner objects without retaining their results unintentionally. Security impact depends on applications memoizing user/tenant-dependent data.

### R09 — P1: nested symlinks bypass filesystem hardening

**Reproduced.** The File/PHP-files constructors inspect the base and `data`, `meta`, `locks` directories, but not the intervening `cache_<namespace>` component (`FileCacheAdapter.php:202`; `PhpFilesCacheAdapter.php:202`). Pre-creating that component as a symlink allowed PHP-files construction and a write into its target.

**Change:** verify each relevant path component and the intended ownership/trust boundary before reading or creating executable cache files. Account for trailing separators, existing files, SQLite targets, and lock/token paths. Reuse the existing filesystem helper where ownership is shared. Keep private trusted roots as a deployment requirement; do not claim portable PHP checks eliminate every filesystem race.

**Acceptance:** final-component and ancestor symlinks, hostile pre-created namespace roots, permission changes, and ordinary legitimate private directories behave predictably. Test Linux and Windows/reparse behavior where supported. Document that PHP-files executes the file before payload HMAC validation and therefore requires a trusted executable-cache directory. The exploit precondition is control of a relevant filesystem path.

### R10 — P2: Redis DSN errors disclose credentials

**Reproduced.** `RedisCacheAdapter::connect()` (`:387`) and `AtomicCounters::connect()` (`:33`) embed the original DSN in exceptions. An invalid database path with synthetic credentials emitted `redis://user:audit-password@localhost/bad`.

**Change:** use sanitized messages and redact secret-bearing public/constructor parameters with `#[SensitiveParameter]` where applicable. Audit passwords, integrity keys, signed-closure keys, MongoDB URIs, and nested previous exceptions. An attribute does not sanitize a message that already contains the secret.

**Acceptance:** sentinel secrets never appear in error messages, exception chains, or rendered traces with argument capture enabled. Invalid scheme, port, database, and authentication failures remain diagnosable without leaking credentials.

### R11 — P2: deferred writes violate read/delete/update ordering

**Reproduced.** `AbstractCacheAdapter::saveDeferred()` stores an object in `$deferred`, but normal reads do not consult it and key deletions/immediate saves do not reconcile it (`:108`, plus adapter mutations). A deferred read returned a miss; delete followed by commit resurrected `"old"`; an immediate `"new"` save followed by commit restored `"old"`.

**Change:** define one coherent deferred-state lifecycle across facade and direct PSR-6 pools. Reads must see pending state; deletion and later writes must supersede it; clear and failed/partial commit must preserve documented semantics. Decide snapshot behavior for caller mutation after queueing. Ensure pending entries eventually persist under the PSR-6 contract, while documenting crash durability limits.

**Acceptance:** a common backend contract suite tests pending read/has/getItems, overwrite/delete/clear/commit ordering, expiration, null values, item mutation, failed commit and retry, and finalization. Preserve ownership validation for foreign items.

### R12 — P2: numeric-string keys and tags break internal maps

**Reproduced.** Public validation accepts `"123"`, but PHP converts numeric string array keys to integers. A numeric tag returned `setTagged=true` followed by a miss; tiered single-key get returned the value while `getMultiple(['123'])` returned a miss. Relevant owners are `Cache::setMultiple()`, `CacheTagSnapshots`, tag encoding/normalization, `TieredCacheAdapter::multiFetch()/saveIntoPool()`, and Node batch copying.

**Change:** keep logical key/tag strings intact through mapping and batching. Use item keys or explicitly reversible internal encodings; do not tighten public validation to exclude previously valid PSR keys merely to avoid the problem.

**Acceptance:** `0`, `123`, `-1`, `01`, 64-character keys, numeric tags, generators, multi-key operations, deferred writes, tier promotion, and atomic operations preserve supported semantics across adapters.

### R13 — P2: skipping L1 write-through retains stale L1 values

**Reproduced.** In `TieredCacheAdapter::save()/writeBatch()` (`:157,242`), `writeToL1=false` skips writing L1 but does not invalidate an already-promoted value. Write old, read/promote, write new, read returned old.

**Change:** invalidate affected upper-tier entries when write-through is disabled. Handle partial tier failures and failed promotions without presenting stale values as authoritative. Apply to single, batch, and deferred writes.

**Acceptance:** after a successful update, earlier promoted values cannot win a later read. Cover nulls, TTL changes, deletes, failed upper-tier invalidation, and concurrent promotion.

### R14 — P1/P2: Redis counters are cleared with ordinary cache data and lose precision

**Reproduced.** `RedisCacheAdapter::clear()` scans `<namespace>:*` (`:183`), deleting `AtomicCounters` keys stored under `<namespace>:counter:*`. A cache clear erased an existing counter. This can reset security-relevant rate-limit state when the same namespace is used. The Lua increment script returns an integer through Lua's numeric representation (`RedisAtomicCounterStore.php:13`): increment by `9007199254740993` returned `9007199254740992` while Redis stored the correct value. `get()` also saturated an out-of-range numeric string to `PHP_INT_MAX`.

**Change:** separate ordinary cache clearing from the counter keyspace; return the exact decimal string from inside the same atomic Lua operation and validate its PHP integer range before conversion. Preserve first-creation TTL and overflow failure behavior.

**Acceptance:** cache clear does not reset counters; boundaries around 2^53 and PHP integer limits are exact; malformed/out-of-range stored values fail safely. Concurrent initialization, decrement, expiration, and injected-client options are covered on Redis and Valkey. No outside-script GET may introduce a race into the returned increment result.

### R15 — P2: Node APCu identity omits the SQLite store

**Reproduced with CLI APCu enabled.** `NodeCache::createApcuAdapter()` uses only the namespace (`:56`). Two Node Cache instances with different SQLite files and the same namespace returned each other's L1 value. Documentation describes the namespace as a logical cache within the selected database.

**Change:** scope APCu and coordination identity to the logical node store plus namespace, with a stable documented identity across intended workers. Expose the same effective namespace to facade locking. Account for independently running CLI/FPM/worker APCu domains when applying cluster invalidation; one CLI consumer does not automatically clear another SAPI's L1.

Related source finding: `Cache` excludes only Tiered and Null adapters when calculating `isAuthoritative()`, so Node's L1/L2 adapter is currently classified as authoritative. The built-in Node factory does not enable signing, which prevents it from satisfying the complete documented authentication-state gate by default. Nevertheless, capability reporting must classify L1-backed/custom configurations honestly rather than relying on a different default option to make them ineligible.

**Acceptance:** different stores remain isolated, intentionally shared stores coordinate correctly, and invalidation reaches every supported L1 domain. Test both APCu-enabled and disabled configurations and cross-process/SAPI topology. If a topology cannot maintain coherence, reject or explicitly constrain it rather than claiming node-wide invalidation.

### R16 — P2: Memcached mishandles TTLs longer than 30 days

**Reproduced.** `save()`, `saveItems()`, and atomic write paths pass relative seconds directly to Memcached. Setting a 31-day TTL returned true and immediately read as a miss.

**Change:** centralize conversion of relative TTL to Memcached expiration semantics for ordinary, bulk, atomic, and lease paths. Preserve zero/forever and expired/no-op contracts and guard timestamp overflow.

**Acceptance:** zero, one second, exactly 30 days, 30 days plus one second, 31 days, DateInterval, and absolute-date inputs have equivalent logical behavior in all applicable APIs.

### R17 — P2: direct PSR-6 validation and APCu bulk delete are inconsistent

**Reproduced.** Direct `ArrayCacheAdapter::getItem('invalid:key')` accepted a reserved PSR character, despite adapters being documented as directly usable PSR-6 pools. `Cache::apcu()->delete('absent')` returned true while `deleteMultiple(['absent'])` returned false (`ApcuCacheAdapter.php:66`).

**Change:** validate every public PSR boundary consistently, preserving efficient internal already-validated paths. Normalize missing-key deletion success for bulk adapters, while distinguishing genuine backend failures. Include expired-item `get()/isHit()` consistency in the contract review.

**Acceptance:** direct pools and facade pass a common PSR-6/PSR-16 interoperability matrix, including invalid keys, missing deletes, null values, expiration and deferred state. Use an independent standards test/consumer where practical.

### R18 — P1, conditional source finding: SQL collation can collapse cache identities

`PdoCacheSchema::install()` uses `VARCHAR(191)` identity columns on MySQL/MariaDB without an explicit case-sensitive/binary collation (`:21`). On a database whose default collation is case-insensitive, distinct supported namespaces/keys differing by case can compare equal. The invalidation schema's cluster/namespace/node columns also inherit database collation. This was not reproduced against MySQL in this audit.

**Change:** use explicit byte-sensitive identity semantics appropriate to supported engines, and provide a migration for existing tables. Audit existing duplicate/collapsed identities before migration; changing only table-creation SQL leaves deployed tables unchanged.

**Acceptance:** real MySQL/MariaDB with a case-insensitive default keeps `Tenant`/`tenant` and `Key`/`key` separate through set/get/bulk/delete/clear/tag/atomic/cluster paths. PostgreSQL and SQLite behavior remains consistent. Record the collation used by each test database.

### R19 — P1 release blocker: verification does not cover the advertised contracts

**Observed.** The baseline quality suite fails, the minimum declared PHP runtime is absent from the inherited matrix, and important backend tests rely on fakes or skipped prerequisites. Selecting ScyllaDB's Alternator service does not test this library's CQL adapter. MongoDB is absent from the workflow service list. Redis Cluster tests using a fake do not prove hash-slot behavior. The inherited Deptrac report leaves 677 dependencies uncovered. Benchmark code primarily measures component operations; some named hit benchmarks also include constructing/filling the cache.

**Change:** repair test/helper structure and provision real backend prerequisites. Resolve Cassandra references with a verified driver/stub strategy that matches supported runtime APIs. Remove skip directives by arranging explicit applicable suites/jobs and hard prerequisite checks, not by disguising skips as returns or passing assertions. Add the full support matrix and meaningful architecture rules. Keep local host and prepared-service reports separate.

**Acceptance:** every required detector runs with its intended scope and thresholds; no new suppressions, exclusions, expanded baselines, raised limits, or weakened assertions. Maintain complexity limits `function=12`, `class=80`, `dependency_tree=120`. A green gate must mean its intended behavior was actually exercised.

## Implementation batches

All checkboxes below are open. Each batch is a separately reviewable change with failing regression evidence first, the smallest correct implementation, and focused verification before the full release gate.

1. **Security and transaction containment — R01, R03, R04, R05, R09, R10.**
   - [ ] Add bounded adversarial subprocess and filesystem/transaction tests.
   - [ ] Correct traversal, policy binding, deletion-result handling, transaction ownership, directory checks, and secret redaction in their existing owners.
   - [ ] Deliver containment fixes in 4.0.0 and document any changed failure behavior; coordinate identity and counter fixes with their batches below.
2. **Authenticated storage and identity — R02, R15, R18.**
   - [ ] Specify the new envelope and logical store/key identity, then test it across all codec-using backends.
   - [ ] Bind Node L1/lock identity to its intended store; migrate SQL identity collation.
   - [ ] Add per-node serialization/integrity options if needed; new optional parameters must retain existing parameter names.
   - [ ] For 4.0, make executable/object deserialization an explicit policy choice and document the default. Include the changed default in the 3.x-to-4.0 migration guide.
3. **Durable invalidation — R06, R07 and R15 topology.**
   - [ ] Choose a commit-safe publication protocol with a written failure-state model and measured contention cost.
   - [ ] Scope cursors correctly, migrate stored progress, and handle empty/reset transport history conservatively.
   - [ ] Test real multi-connection delivery, retention, crash recovery, poison events, administrative skips, and SAPI/L1 coherence.
4. **Cache contracts and memoization — R08, R11, R12, R13, R16, R17.**
   - [ ] Add reusable cross-backend behavioral tests for keys, values, deferred operations, expiration, tagging, promotion, and atomic outcomes.
   - [ ] Fix memoizer identity/lifecycle and test persistent workers with request resets.
   - [ ] Correct numeric maps, upper-tier invalidation, Memcached expiration, and direct PSR pool boundaries.
5. **Counter and backend failure contracts — R14 plus targeted race review.**
   - [ ] Isolate counter clearing and make integer handling exact.
   - [ ] Audit stale-read cleanup so deleting an observed stale value cannot erase a concurrent replacement; use compare-delete or leave cleanup to bounded maintenance where appropriate.
   - [ ] Audit tag initialization races, clear versus write/consume, lease loss, partial bulk failure, and Redis/Memcached false/error status handling. These races need deterministic interleaving tests; source inspection alone is not a completed gate.
6. **Tooling, documentation, and release verification — R19.**
   - [ ] Resolve the recorded skip/reference findings and make architecture boundaries meaningful.
   - [ ] Review clone groups and centralize genuinely shared invariants in existing owners; keep backend-specific atomic protocols explicit. Do not perform a broad inheritance rewrite or consolidate only to reduce file count.
   - [ ] Update README, security/serialization/atomic/Node/Cluster docs and executable examples to the final behavior.
   - [ ] Complete migrations, benchmark/soak evidence, clean consumer tests, and exact-revision CI before tagging.

7. **Optional Runwire 2.1 integration — candidate scope, not a 4.0 release blocker.**
   - [ ] If this workstream is selected, implement automatic use of relevant active Runwire capabilities with the normal path as fallback, and demonstrate it in an executable invalidation-worker example after R06, R07, and R15 are resolved.
   - [ ] Evaluate bounded maintenance scheduling and persistent-request lifecycle integration; implement where an actual consumer or the example establishes a concrete need.
   - [ ] Evaluate Runwire for isolated crash/concurrency regression tests during earlier batches without making it a core dependency.
   - [ ] Complete the compatibility, lifecycle, coherence, and performance gates below for every shipped integration capability.

## Optional Runwire 2.1 integration workstream

### Scope and dependency decision

The assessment inspected local Runwire tag `2.1` (`e6a954df1ec90aef98daf8248bd02f741f9324e3`). This establishes available APIs and platform requirements, not successful CacheLayer integration or a measured throughput benefit. Worker supervision, structured coroutines, and lifecycle support already existed before 2.1; the principal 2.1 additions concern adaptive HTTP scheduling.

Runwire requires 64-bit PHP 8.4+, while CacheLayer supports PHP 8.3+. If selected, implement the smallest integration needed for automatic capability selection, with an executable example and an isolated integration-test Composer environment using `infocyph/runwire:^2.1`. Do not add Runwire to core `require` or make the default PHP 8.3 development/test installation require it. Add a Composer suggestion only when usable integration documentation exists. Introduce a separate optional package or adapter only if tested consumers demonstrate substantial reusable behavior beyond the example; do not introduce a generic runtime abstraction speculatively.

The core must remain usable without Runwire installed, including ordinary PHP-FPM execution. No supervisor, listener, timer, connection, or worker may start during autoload or cache construction. A host that already owns its process pool retains that ownership. The 4.0 major-version decision does not change these dependency and runtime boundaries.

### Automatic capability selection and normal fallback

**Execution contract:** Runwire loaded as the active runtime → use the relevant supported capability; Runwire absent, inactive, or lacking that capability → use the existing normal path. Callers keep the same CacheLayer APIs and do not select a Runwire-specific cache backend or enable each capability manually. Installation or an autoloadable Runwire class alone does not establish an active runtime, event loop, or request scope.

Use the active runtime's public context and supported lifecycle hooks. Runwire 2.1 exposes `RuntimeContext` and explicit coroutine scopes; do not assume a process-global current-runtime/current-scope lookup exists. Where context must be supplied by the hosting application, bind it once at the runtime bootstrap boundary and attach the actual request/task scope at its lifecycle boundary. Capability selection within CacheLayer is automatic after that binding. The executable example must make this wiring concrete rather than promise unsupported discovery.

- Use lifecycle cleanup and isolated request/task state when the active host provides them. In concurrent execution, preserve request isolation; never silently substitute a process-global memoizer or global reset for unavailable request-local state. A safe normal path may bypass request memoization when isolation cannot be established.
- Use cooperative timer waits for existing retry/poll intervals only inside an appropriate active scope, preserving timeout, cancellation, lease, and atomicity contracts. Elsewhere retain the normal bounded wait path. Backend calls remain synchronous unless their actual client integration supports cooperative I/O.
- Schedule already-configured invalidation or maintenance work on the runtime's available worker/loop lifecycle where ownership and blocking behavior permit. Do not create new background jobs merely because Runwire is present. Without those capabilities, keep the existing explicit consume/maintenance execution path.
- Resolve stable capabilities at worker bootstrap; resolve request/task ownership at the current scope. Refresh bindings after fork, worker replacement, or runtime shutdown. Do not retain one request's scope in a process-global cache or repeatedly scan/reflection-probe dependencies on every cache hit.
- Fall back when a capability is unavailable before starting an operation. Do not catch operational failure or cancellation and replay a potentially completed mutation through the normal path. Preserve error policy, one-time consumption, distributed locks, payload integrity, TTL, and cursor semantics across both paths.

### Planned uses and prerequisites

1. **Supervised cluster invalidation — first deliverable if selected.** Wrap existing `ClusterRuntime::consume()` calls in bounded scheduled work with explicit batch size, polling interval, backend timeouts, retry/backoff, and shutdown budgets. Preserve serial consumption within each complete cursor scope; independent scopes may run independently. Create backend connections in worker bootstrap after a fork. Expose consumed counts, failures, consumer lag, and restart behavior without unbounded metric labels. Resolve R06/R07 before relying on durable progress and R15 before claiming node-wide L1 coherence. A separate CLI consumer must not be described as clearing unrelated FPM/worker APCu domains automatically.
2. **Bounded maintenance — evaluate for inclusion.** Schedule existing `NodeCacheMaintenance::pruneExpired()`, `checkpoint()`, and `optimize()` at explicit operational intervals. Bound prune batches and prevent overlapping maintenance against the same store. Measure SQLite writer contention and choose heavier maintenance windows accordingly. Do not place full scans or maintenance on the request hot path. Supervision does not make an individual blocking database operation cancellable.
3. **Persistent request lifecycle — conditional on the host integration.** After R08 and the relevant deferred-state fixes, connect request-owned memoizer/state cleanup to Runwire's completion/reset lifecycle, including failure, cancellation, and deadline paths. `flush_memoizers()` is suitable only for a sequential lifecycle with an explicit ownership contract. Concurrent requests need isolated memoizer state, potentially through Runwire task-local context or an explicit request-owned instance; one request must not flush or observe another request's state. Preserve intentional cross-request cache data and resolve pending deferred writes under their documented contract.
4. **Crash and concurrency verification — usable during earlier batches.** Evaluate Runwire's bounded subprocess runner for recursive-payload probes and its worker supervision for real restart/concurrency tests. Set explicit PHP memory, execution-time, and output limits; execute validated argument vectors. `ProcessRunner` is synchronous and is not itself a parallel worker pool or OS sandbox. Retain a lightweight existing subprocess harness if adopting Runwire adds complexity without improving evidence. PHP 8.3 core regression coverage must remain available independently.

Existing PDO, filesystem, and synchronous native-client calls remain blocking inside Runwire coroutines. Prefer existing backend bulk operations and bounded dedicated workers where suitable. Do not wrap each cache operation in a coroutine or process and claim asynchronous I/O or a speedup. Runwire's in-process coroutine synchronization also does not replace CacheLayer's cross-process/distributed lock and atomicity contracts.

### Integration acceptance gates

The entire Runwire workstream, including the invalidation-worker example, may be deferred without blocking 4.0.0. Record an inclusion/defer decision based on concrete need and evidence; the checkboxes in this section apply only to capabilities selected for shipping. Every shipped capability must pass its applicable gates; deferred capabilities must remain explicitly unadvertised. None of these decisions excuses any R01–R19 requirement.

- [ ] Keep a clean PHP 8.3 consumer and the default core test environment working without Runwire. Test the optional environment on supported 64-bit PHP 8.4/8.5 with lowest and highest supported dependencies; record the resolved Runwire version and native extensions.
- [ ] Verify automatic selection with Runwire absent, installed but inactive, active with each relevant capability, and active with partial capabilities. Cover calls outside a request/task scope and contexts after shutdown, fork, and worker replacement. Confirm the normal path remains usable without Runwire classes loaded.
- [ ] Run the same cache contract cases through normal and runtime-assisted paths. Prove no duplicate mutation on failure/cancellation, no changed integrity or distributed-atomicity guarantees, no cross-request scope leakage, and no automatic job startup from package presence. Verify the bootstrap binding and lifecycle cleanup in the executable example.
- [ ] Declare topology prerequisites. Native prefork supervision needs PCNTL/POSIX; portable single-process execution relies on external supervision for restarts. Test supported modes explicitly and fail clearly for unsupported requested capabilities.
- [ ] Demonstrate no skipped committed invalidations through reversed commits, duplicate replay, worker death before/after application and cursor persistence, restart, retention, backend outage, and graceful shutdown. Prove cursor ownership and L1 coherence for each advertised deployment topology.
- [ ] Bound batch work, queueing, retry frequency, backend wait time, shutdown duration, and retained memory. Test crash loops and verify that backoff does not starve lifecycle handling. Maintenance must not overlap unexpectedly or exceed the recorded SQLite contention budget.
- [ ] Soak-test sequential and, if supported, concurrent requests with changing tenants, failures, cancellations, deadlines, and deferred writes. Require no memoizer leakage, cross-request resets, abandoned request state, or unbounded memory growth.
- [ ] Compare representative host-application successful RPM with and without the integration under equivalent correctness guarantees, topology, resources, and workloads. Record invalidation lag, p95/p99 latency, errors/timeouts, CPU/RSS, backend calls, and maintenance contention using the release measurement method below. Set acceptable budgets before selecting an implementation.
- [ ] Treat Runwire 2.1 adaptive HTTP scheduling as a separate host-level experiment. Begin with protocol defaults (`FIXED`), and evaluate `LATENCY`, `THROUGHPUT`, or `AUTO` only through repeated representative measurements, including load transitions and fairness. Do not attribute HTTP scheduling gains to CacheLayer storage or change protocol hard limits.
- [ ] Run executable examples and integration jobs on the exact final revision, and document startup, shutdown, connection ownership, prerequisites, topology limits, recovery, and rollback. Keep integration evidence separate from core/backend gate results.

## Improvements that require measurement or a separate scope decision

These are not substitutes for the required fixes:

- Bound large Node SQLite read/delete/tag parameter lists and remote bulk payloads using verified backend limits. Test above the configured SQLite variable limit instead of assuming one universal limit.
- Bound Scylla's prepared-statement cache: variable-sized `IN`/batch shapes can grow its per-instance map in persistent workers. Choose a fixed chunking strategy or measured bounded cache.
- Add appropriate bounded expiry maintenance for file/PHP-files, in-memory stores, and MongoDB where physical retention can outlive logical expiration. Avoid request-path full scans. Never casually delete live lock files: replacing a locked inode can split the lock domain.
- Separate fixture construction/cold loading from warm cache hit measurements. Benchmark signed/plain/compressed records, batches, tagged hits, miss/fill, tier promotion, and contention.
- Measure default metrics overhead before adding a no-op option; keep observability hooks cheap. Do not claim a performance improvement from code shape alone.
- Review the moving PHPForge workflow reference and development dependency constraints for reproducible releases. Pin a reviewed workflow revision and record the resolved toolchain where compatible with project policy. Do not silently revert the user's MongoDB constraint edit.
- Track `doctrine/annotations` abandonment through PHPBench upstream. It is a development-maintenance warning, not a demonstrated runtime vulnerability; do not delete working benchmarks just to remove the warning.

## Release acceptance

### Correctness and security

- [ ] Every R01–R19 item is resolved with targeted evidence or, for a suspected source finding, disproved with a documented test on the actual affected backend.
- [ ] Run real PHP 8.3, 8.4, and 8.5 with highest supported and lowest supported dependency sets and `E_ALL`. Verify optional extensions and native-client versions explicitly.
- [ ] Exercise SQLite, MySQL, MariaDB, PostgreSQL, Redis, Valkey, Memcached, MongoDB, Scylla CQL, and real Redis Cluster for their advertised features. Fakes supplement these gates.
- [ ] Use separate processes/connections for one-winner claims, one-time consumption, tag initialization, invalidation, clear/write races, and lock expiration/ownership. An in-process fake cannot prove distributed atomicity.
- [ ] Verify executable-file and ordinary-file behavior with OPcache enabled/disabled, Linux permissions, and Windows where supported. Test failure paths without granting the cache process excess permissions.
- [ ] Run an independent PSR consumer/contract check. Keep cache/authentication-state topology and integrity/replay guarantees explicit.

### Performance and worker stability

- [ ] Before hot-path changes, record a reproducible baseline on production-equivalent hardware; correctness fixes remain required even if they add necessary work.
- [ ] Measure both component operations and representative host-application **successful RPM**. Do not convert a PHPBench microbenchmark into an application-throughput claim.
- [ ] Cover cold/warm initialization, hits/misses/fill, invalid/tampered inputs, signed/compressed payloads, bulk/tagged reads, atomic/counter contention, and invalidation consumption at several concurrency levels.
- [ ] Use at least three warmed steady-state runs per important workload; compare median sustained successful RPM and variance. Record RPS/RPM, duration, counts, errors/timeouts, validation failures, p50/p95/p99, CPU, memory, queue/consumer lag, connections, cache hit rate, and backend calls where relevant.
- [ ] Set workload-specific latency, memory, connection, and throughput budgets from that baseline before accepting optimizations. A provisional 2% RPM regression budget may be used only in a matching stable environment with noise below the decision threshold; define exact capacity limits in the recorded benchmark configuration.
- [ ] Run persistent-worker soak tests with changing tenants, collected/reused objects, request resets, cache churn, and dependency failures. Require bounded memory and lag and no stale identity reuse.

### Tooling and packaging

```sh
composer ic:doctor
composer ic:list-config
composer ic:active-config
# During implementation, not during this read-only audit:
composer ic:process
composer ic:tests:details
composer ic:release:guard
git diff --check
```

- [ ] Keep source-mutating processors sequential and review their diff. Parallelize only independent read-only checks with bounded concurrency.
- [ ] Build documentation with warnings as errors and test the examples relevant to changed public contracts.
- [ ] Install the candidate in a fresh consumer using `composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction`; verify optional adapters are lazy and runtime code does not depend on development packages.
- [ ] Recheck advisories against both the resolved candidate and production-only dependencies. The current untracked development lockfile is evidence for this checkout, not every consumer resolution.
- [ ] Require all configured CI checks on the **exact final commit**, including stable/lowest jobs, before creating a release tag. Historical CI does not validate later edits.

### Migration and rollback

- [ ] Publish a consolidated 3.x-to-4.0 upgrade guide covering changed defaults, public behavior, payload/storage formats, cursor migration, and optional Runwire requirements. Record all intentional breaks in the 4.0.0 release notes.
- [ ] Version and publish the authenticated record and cursor/schema changes. Preserve a clear distinction between disposable cache values and durable security/cursor state.
- [ ] Use separate namespaces/storage versions or a coordinated cutover where old/new readers cannot safely coexist. In the new integrity mode, do not silently accept unbound legacy records for compatibility.
- [ ] For cursor migration, clear/reconcile the affected local cache and establish a safe replay position; do not merely copy a shared cursor into several scopes and assume it proves delivery.
- [ ] Migrate SQL collations with explicit old-data inspection and rollback instructions. Preserve counters and authoritative replay/authorization state; do not treat deleting that state as ordinary cache cleanup.
- [ ] Rehearse rollback by activating the complete previous release and its compatible storage configuration. Record immutable commit/tag, PHP/extensions, tool versions, schema versions, and deployment assumptions.

## Reproduction notes

These short examples use an isolated test process. Do not run the deliberate exhaustion or filesystem fault probes in an application worker.

```php
// Deferred deletion is undone by commit on the audited revision.
$cache = \Infocyph\CacheLayer\Cache\Cache::memory();
$cache->saveDeferred($cache->getItem('x')->set('old'));
$cache->delete('x');
$cache->commit();
assert($cache->get('x') === 'old'); // Observed defect; fixed expectation is a miss.

// A recursive input is tiny; a payload-byte limit cannot bound traversal.
$value = [];
$value['self'] = &$value;
$blob = 'cl2:' . serialize([
    'format' => 2, 'encoding' => 'native', 'value' => $value,
    'expires' => null, 'tags' => [], 'namespace' => null,
]);
// Decode only in a subprocess with an OS timeout and PHP memory limit.

// Distinct closures on one line collide in the audited memoizer.
$a = static fn() => 'A'; $b = static fn() => 'B';
$memo = \Infocyph\CacheLayer\Memoize\Memoizer::instance();
$memo->flush();
assert([$memo->get($a), $memo->get($b)] === ['A', 'A']);
```

Local audit artifacts, useful while this workspace session remains available:

- `/tmp/cachelayer-audit-quality.log`, `/tmp/cachelayer-audit-doctor.log`
- `/tmp/cachelayer-audit-advisories.json`, `/tmp/cachelayer-audit-release-audit.log`
- `/tmp/cachelayer-audit-integration.log`
- `/tmp/cachelayer-audit-probes.php`, `/tmp/cachelayer-audit-cycles.php`
- `/tmp/cachelayer-audit-network.php`, `/tmp/cachelayer-audit-pg.py`
- `/tmp/cachelayer-audit-scope.php`, `/tmp/cachelayer-audit-file-consume.php`

The temporary network probe uses the audit's allocated ports; recreate disposable services and update ports before reuse. These artifacts are not permanent regression tests. Promote the relevant cases into the existing test layout during implementation.

## Primary references

- [PHP-FIG PSR-6](https://www.php-fig.org/psr/psr-6/) establishes deferred-read visibility, supported keys, miss behavior, and deletion semantics underlying R11/R17.
- [PHP unserialize documentation](https://www.php.net/manual/en/function.unserialize.php) describes deserialization risks and options; limits on unserialization do not bound subsequent application traversal in R01.
- [PostgreSQL transaction isolation](https://www.postgresql.org/docs/current/transaction-iso.html) explains committed-row visibility and sequence behavior. R06's delivery failure is an inference from those semantics plus the library query, independently reproduced with two transactions.
- [Redis Lua API conversion rules](https://redis.io/docs/latest/develop/programmability/lua-api/) explain numeric reply conversion relevant to the reproduced R14 precision failure.
- [PHP Memcached expiration rules](https://www.php.net/manual/en/memcached.expiration.php) specify the 30-day relative/absolute cutoff underlying R16.
- [PHP object ID lifetime](https://www.php.net/manual/en/function.spl-object-id.php) documents ID reuse after destruction, relevant to R08.
- [MySQL case sensitivity and collation](https://dev.mysql.com/doc/refman/8.4/en/case-sensitivity.html) explains why inherited case-insensitive collations affect the identity columns in R18. The Batch 2 backend regression now covers the required byte-sensitive identity behavior; broader final backend-matrix coverage remains under R19.
