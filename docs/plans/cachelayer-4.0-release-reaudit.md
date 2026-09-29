# CacheLayer 4.0 release re-audit

Date: 2026-09-29

Audited commit: `51fcba79ebac14b7ddb767e80c724a1eea485e9e` (clean working tree before this audit).

**Decision: hold the 4.0.0 release. Nine reproduced findings remain despite green CI.** No production code or dependency changes were made during this review. This report follows [PHPForge engineering principles](../../vendor/infocyph/phpforge/resources/engineering-principles.md) and reopens the relevant gates in the [implementation plan](cachelayer-4.0-security-correctness-plan.md).

The current contract is PHP 8.4+, with PHP 8.4/8.5 verification and a shipped Runwire 2.1 integration that remains optional for consumers. This review evaluates that updated contract, including sharing the framework's runtime and request/task scopes.

## Scope and evidence

The checkout contains 114 production PHP files and 38 test files. Review covered the security/correctness changes since 3.4 across codecs, adapter policy, deferred/atomic operations, local and remote adapter families, counters, locks, memoizers, Node/Cluster storage, outbox ordering, cursor recovery, Runwire lifecycle integration, packaging, release workflows and documentation. Automated checks cover their configured repository scope; targeted adversarial probes below exercise gaps in the existing tests. This is not a claim that every possible defect has been excluded.

| Check | Result on the audited candidate |
| --- | --- |
| `composer ic:doctor`, `ic:list-config`, `ic:active-config` | Setup resolves; doctor reports healthy. |
| Normal-host `composer ic:tests:details` | **Failed:** Pest aborts during discovery with `APCu must be enabled for CLI tests.` No complete host Pest result. |
| Normal-host `composer ic:release:guard` | **Failed:** same CLI APCu prerequisite. |
| Host static/style checks | Syntax, references, skip-directive scanner, duplicate/comment checks, Pint, PHPCS, PHPStan, Psalm, Rector and configured Deptrac passed. Deptrac reports 774 uncovered dependencies; duplicate report has 27 clone groups / 1,169 lines / 7.13%. These passes do not establish complete architecture coverage. |
| Prepared host subset | PHP 8.5.4 with `apc.enable_cli=1`, isolated Redis 8.10, Valkey 9.1 and Memcached 1.6.45: **324 passed, 2 failed, 1,240 assertions** across 34 selected test files. Both failures require an unprovisioned Scylla Alternator service. MySQL, PostgreSQL, SQL-identity/multi-engine and real MongoDB test files were not selected in this local subset. |
| Core release smoke | Passed on host PHP 8.5.4 with CLI OPcache off and on. |
| Runwire worker example | Passed against the checkout's installed Runwire 2.1. |
| Runwire certification and soak | Both passed using temporary copies that point only their autoload line at this checkout. Soak: 2,048 sequential / 256 concurrent requests; 2,277 validated, 10 intentional failures, 9 cancellations, 8 deadlines; retained-memory growth 6,291,456 bytes. This is installed-checkout evidence, not a fresh consumer install. |
| Composer validation/platform | `composer validate --strict` and `composer check-platform-reqs` passed. |
| Live locked dependency audit | Zero reported advisories; `doctrine/annotations` remains abandoned through development tooling. This does not cover defects in this package or all future consumer resolutions. |
| Local documentation build | Not run: the host lacks Sphinx. Exact-commit CI documentation build passed. |
| Remote CI | Both release workflows succeeded on the exact audited commit; details below. |

GitHub API verification found:

- [Security & Standards run 36514617642](https://github.com/infocyph/CacheLayer/actions/runs/36514617642): stable/lowest PHP 8.4/8.5 QA, analysis, benchmarks and clean install succeeded. The conditional Security Report job was skipped; the workflow result is success.
- [Release Verification run 36514617203](https://github.com/infocyph/CacheLayer/actions/runs/36514617203): all 14 jobs succeeded, covering Linux/Windows core smoke, clean consumers, independent PSR contracts, docs, real Redis Cluster, real Scylla CQL and Runwire PHP 8.4/8.5 lowest/stable consumers.

Those runs validate the existing checks, not the additional failing cases below. Host-application production throughput, the complete framework/router integration and production deployment topology were not independently certified here.

## Required findings

P1 denotes a release blocker involving security, durable delivery or core atomicity. P2 denotes a correctness/confidentiality issue that must also be resolved before this release is described as fulfilling its current contracts. These priorities are not CVSS scores.

### F01 — P1: deferred state defeats one-time atomic consumption

**Reproduced with signing enabled and `failOpen=false` on memory, File, PHP-files, Redis and Memcached.** Store `x=stored`, queue `x=pending` through `saveDeferred()`, call `atomic()->getAndDelete('x')` twice, then commit. Both consumes return `pending`, and commit restores it to storage. SQLite was a control: it returned `stored`, then a miss, and did not resurrect the key.

The atomic paths use helpers that overlay deferred state, without consistently consuming/discarding that state. Even a backend miss becomes a hit through `genericMiss()`. See [AbstractCacheAdapter.php](../../src/Cache/Adapter/AbstractCacheAdapter.php) (`genericItemFromRecord`, line 298; `genericMiss`, line 316) and [ArrayCacheAdapter.php](../../src/Cache/Adapter/ArrayCacheAdapter.php) (`atomicGetAndDelete`, line 52), plus the equivalent remote/file paths. Backend atomic deletion alone is insufficient when the facade can repeatedly return an unconsumed local value.

**Change:** define atomic/deferred interaction explicitly in the existing owners. Atomic reads must not turn a backend miss into an unconsumed pending hit; reconcile or reject pending state under a consistent contract. Review set-if-absent and compare-and-set as well.

**Acceptance:** a shared suite covers pending-only and persisted-plus-pending values, expiration, failure/retry, signing, repeated consume and subsequent commit across every advertised atomic backend. At most one successful consume, with no later resurrection.

### F02 — P1: clearing namespace `cachelayer` erases unrelated counters and live locks

**Reproduced on real Redis.** `Cache::redis('cachelayer', client: $redis)->clear()` scans `cachelayer:*`. This also matches the new `cachelayer:counter:<namespace>:<key>` domain and the default `cachelayer:lock:` domain. A counter in namespace `audit-counter` changed from 5 to missing. A 30-second lock was acquired, the cache was cleared, and a second owner successfully acquired the same lock while the first handle remained live. The default invalidation-stream prefix overlaps too (source finding).

See [RedisCacheAdapter.php](../../src/Cache/Adapter/RedisCacheAdapter.php), `clear()` line 185; [RedisAtomicCounterStore.php](../../src/Counter/RedisAtomicCounterStore.php), `COUNTER_PREFIX`/`map()`; and [RedisLockProvider.php](../../src/Cache/Lock/RedisLockProvider.php), default prefix. `cachelayer` is a valid public namespace. Valkey shares the Redis adapter implementation.

**Change:** make clear operate only on explicitly owned data/metadata domains, or use a structurally disjoint physical layout. Moving only the counter prefix is insufficient. Preserve operational state in migration/rollback.

**Acceptance:** clear every boundary namespace, including `cachelayer`, while counters, locks and invalidation streams exist. Counter values/TTLs survive, held locks exclude a second owner, and stream history remains intact.

### F03 — P1: closure fingerprinting still permits recursive exhaustion

**Reproduced in isolated PHP processes with a 32 MB memory limit and an 8-second external timeout.** Both examples terminate with memory exhaustion, exit 255:

```php
$a = [];
$a['self'] = &$a;
$f = static fn() => $a;
memoize($f);

// Separate process:
$f = null;
$f = static function () use (&$f) { return 1; };
memoize($f);
```

[CallableFingerprint.php](../../src/Memoize/CallableFingerprint.php), lines 67–97, normalizes closure captures without the traversal check used by `value()`. Closure fingerprints are recorded only after traversing captures, so self/mutually captured closures also recurse before being registered. The callback itself need not execute recursively. Input can be entirely legitimate application state; remote exploitability depends on how an application builds closures/captures.

**Change:** establish identity before traversing captures or avoid traversing captures when instance identity already determines the contract; otherwise use cycle-safe bounded traversal across both arrays and closure references.

**Acceptance:** recursive captured arrays, self/mutual closures, deep captures and ordinary callbacks terminate safely without fatal errors or unbounded diagnostic output. Preserve intended memoizer hit behavior.

### F04 — P1: traversal budget is checked after unbounded queue allocation

**Reproduced through the codec.** An unsigned native record with 350,000 scalar array entries is **4,439,019 bytes**, below the default 8 MB payload limit. Decoding it exhausts a **128 MB** process at `BoundedValueTraversal.php:48`, before it can reject the value against `MAX_NODES=65,536`. A 100,000-entry / 1,189,019-byte record similarly exhausts a 32 MB process.

[BoundedValueTraversal.php](../../src/Support/BoundedValueTraversal.php), lines 22–30 and 45–51, checks the budget while popping nodes but appends all children first. The helper therefore allocates a frame for each child before enforcing its limit. Backend attack preconditions are unsigned writable data or a writer authorized to produce a signed oversized graph; signing does not solve writer-side resource bounds.

**Change:** enforce the remaining node/queue budget before adding children and use traversal storage bounded by the stated limits. Retain independent encoded-byte and decompression bounds.

**Acceptance:** wide, deep, cyclic and aliased graphs fail safely under explicit process memory/time ceilings on encode and decode; modest supported graphs retain their values. Test both sides of the node limit, not only recursive arrays.

### F05 — P1: fully lost invalidation history leaves stale local values valid

**Reproduced with the real PDO implementation in its SQLite testing mode.** Consume an event and persist its cursor, cache `x=stale`, publish a later invalidation for `x`, then remove all retained events before the consumer sees it. `recoverIfRequired()` returns false and the cached stale value remains readable.

[ClusterRecoveryManager.php](../../src/Cluster/Recovery/ClusterRecoveryManager.php), lines 30–33, returns early whenever the oldest event is null. A previously consumed cursor plus empty history is not proof that no invalidation was missed. Reset histories with IDs behind a stored cursor also need an explicit contract; that related path is identified by source review, not claimed as separately reproduced here.

**Change:** distinguish a never-used transport from history loss/reset, with a durable epoch/high-watermark or another concrete recovery protocol. Reconcile local state before treating an unprovable cursor as current. Avoid repeated unnecessary clears of known-empty history.

**Acceptance:** full retention loss, stream deletion/recreation, reset sequence IDs, restart and normal empty startup preserve safety on real Redis and SQL transports, with a documented recovery position.

### F06 — P2: stale-read cleanup can delete a concurrent replacement

**Reproduced with real Redis and a deterministic read interleaving.** A Redis subclass performs the actual GET, writes a valid replacement through the actual connection before returning the observed invalid payload, and lets normal adapter code continue. `getItem()` then deletes by key; the next read misses instead of returning the valid replacement.

[RedisCacheAdapter.php](../../src/Cache/Adapter/RedisCacheAdapter.php), lines 228–238, still uses unconditional DEL in the single-key path. Bulk cleanup already uses `RedisValueGuard`. The facade also unconditionally deletes stale-tag keys in [Cache.php](../../src/Cache/Cache.php), lines 913 and 940; a separate tagged-read interleaving should be a required regression because the same race can bypass adapter-local fixes.

**Change:** use compare-delete on the exact observed record where supported, or leave physical cleanup to safe bounded maintenance. Audit both adapter and facade cleanup owners.

**Acceptance:** a valid replacement inserted between read/validation and cleanup survives single, bulk and tagged reads; ordinary stale records still produce misses. Exercise Redis/Valkey and each applicable backend contract.

### F07 — P2: an unrelated successful operation re-enables stale L1 entries

**Reproduced with a deterministic L1 failure fixture and the real TieredCacheAdapter.** Promote `x=old`; fail L1 invalidation while writing `x=new`; the adapter correctly fences L1 and reads `new`. Restore L1 deletion, then successfully write unrelated key `y`. The next read of `x` returns `old`.

[TieredCacheAdapter.php](../../src/Cache/Adapter/TieredCacheAdapter.php), `invalidateSkippedL1()` line 276, sets the global `l1Readable` flag from the latest key's result. `finishL1Write()` and successful individual deletes likewise restore global readability without clearing every stale entry.

**Change:** keep L1 fenced until a successful full reconciliation/clear, or track invalidity at an appropriate per-key scope. An unrelated successful operation does not establish whole-tier coherence.

**Acceptance:** after failures for one or several keys, unrelated saves/deletes and partial recoveries cannot expose stale values. Include bulk and multi-tier configurations.

### F08 — P2: Redis authentication secrets remain exposed in exception traces

**Reproduced on real Redis using only a synthetic secret.** With `zend.exception_ignore_args=0` and `zend.exception_string_param_max_len=128`, an authentication error exposes `AUDIT_SENTINEL_40` in the `RedisConnection::authenticate(Object(Redis), 'AUDIT_SENTINEL_40')` frame. The public DSN and native Redis auth frames are redacted, but the intermediate helper is not.

See [RedisConnection.php](../../src/Support/RedisConnection.php), line 44. This affects applications that capture exception arguments or render detailed traces; default trace-display settings may hide it without fixing the stored arguments.

**Change:** mark the intermediate credential parameter sensitive and trace secret-bearing arrays/parameters throughout authentication and connection failures. Keep error messages and previous exceptions sanitized.

**Acceptance:** synthetic password and ACL credentials are absent from rendered traces and inspectable unredacted argument values on authentication, connection, parsing and database-selection failures.

### F09 — P2: flushing one request invalidates another request's memoizer identities

**Reproduced through Runwire request scopes and separately through two isolated Memoizer instances.** Request A memoizes a retained callback returning incrementing values. Request B creates its memoizer and calls `flush_memoizers()`. Request A calls the same callback again and gets 2 rather than its existing cached 1: observed `[first=1, second=2, executions=2]`.

[Memoizer.php](../../src/Memoize/Memoizer.php), line 44, and [OnceMemoizer.php](../../src/Memoize/OnceMemoizer.php), line 35, both call the process-global [CallableFingerprint::flush()](../../src/Memoize/CallableFingerprint.php) at line 47. This removes identity mappings still used as keys by other live request-owned memoizers. Request attributes isolate value storage but do not isolate identity resets.

**Change:** keep object/closure identities stable for their lifetime across independent memoizer flushes, or scope identity ownership consistently with memoizer state. Do not introduce an unbounded strong-reference registry.

**Acceptance:** flushing/completing/failing one request does not change another request's `memoize`, object `remember`, or `once` behavior. Test interleaved scopes, ordinary isolated instances, and bounded collection of dead objects.

## Remediation and release gates

All new findings remain open. Preserve the existing successful fixes and add targeted regressions in the current test layout; do not weaken existing PHPForge or CI checks.

- [ ] Correct F01–F05 before treating the release as safe for one-time state, shared Redis domains, hostile/recursive values or durable cluster invalidation.
- [ ] Correct F06–F09 and verify their failure/interleaving paths in existing owners.
- [ ] Recheck the related original findings: R01/R08 traversal, R04/R11 atomic/deferred state, R06/R07 recovery, R10 redaction, R13 tier coherence, R14 counter isolation and Batch 7 request isolation.
- [ ] Extend regression coverage across backend implementations instead of testing only new helper classes; F06 demonstrates why a passing helper test does not prove every caller uses it.
- [ ] Run the normal host commands with documented prerequisites, and report prepared service/container evidence separately. Do not turn missing-service failures into skipped/passing assertions.
- [ ] Re-run core, independent PSR consumer, Runwire lifecycle/certification/soak, real backend, documentation and configured stable/lowest checks on the corrected final commit before tagging 4.0.0.
- [ ] Update release notes and plan completion claims only after the reopened cases pass. Green CI on `51fcba7` remains historical evidence for the pre-remediation candidate.

Additional verification improvements: the Runwire certification output currently reports zero `backend_gets`/`backend_sets` despite cache operations because it reads the exported metrics at the wrong shape; the timing denominator also includes warmup while the RPM numerator excludes it. Fix those measurements before using them for quantitative decisions. Keep this short CLI workload separate from sustained host-application throughput claims. The configured Deptrac coverage gap also remains visible despite a passing gate.

## Local reproduction artifacts

The audit used bounded processes and isolated data; it did not run exhaustion probes inside an application worker. Temporary Redis/Valkey/Memcached containers were created solely for this audit and removed afterward.

Local artifacts retained for the current workspace session:

- `/tmp/cachelayer40-probe.php`: closure cycles, traversal, isolated/request memoizer flush and deferred policy probes.
- `/tmp/cachelayer40-wide.php`: larger codec traversal reproduction (run `wide` with `memory_limit=128M`).
- `/tmp/cachelayer40-more.php`: real Redis cleanup/counter probes, tier recovery and empty history reproduction.
- `/tmp/cachelayer40-atomic.php`: signed cross-backend atomic/deferred reproduction.
- `/tmp/cachelayer40-quality.log`, `/tmp/cachelayer40-release-guard.log`, `/tmp/cachelayer40-prepared-tests.log`.
- `/tmp/cachelayer40-certify.log`, `/tmp/cachelayer40-soak.log`, `/tmp/cachelayer40-audit.json`, `/tmp/cachelayer40-ci.json`.

The network probes require fresh isolated services and their configured loopback ports; never point them at a shared or production Redis database because they deliberately exercise clear and invalidation failure cases. Promote the reproductions into permanent regressions during remediation. Temporary artifacts are not a substitute for committed tests.
