# CacheLayer 4.0 release re-audit

Date: 2026-09-29

Audited commit: `51fcba79ebac14b7ddb767e80c724a1eea485e9e` (clean working tree before this audit).

**Latest decision: F01–F09 and V01–V03 are resolved in the working tree; release sign-off awaits verification of the final committed revision.** The nine findings were reproduced on the audited commit and corrected on `feature/improvements`. Substantive head `97957893ab1459e365526dab2b913131021489fe` passed Security & Standards #513 and Release Verification #153; tracker-closure head `4f4e3912bccba11c2ba9e2ef6b1ee60a26c8a2c5` then passed Security & Standards #514 and Release Verification #154. No production code or dependency changes were made during the original review. This report follows [PHPForge engineering principles](../../vendor/infocyph/phpforge/resources/engineering-principles.md) and reopens the relevant gates in the [implementation plan](cachelayer-4.0-security-correctness-plan.md).

The current contract is PHP 8.4+, with PHP 8.4/8.5 verification and a shipped Runwire 2.1 integration that remains optional for consumers. This review evaluates that updated contract, including sharing the framework's runtime and request/task scopes.

## Resolution of V01–V03 (working tree, 2026-09-29)

Implemented against base `69845554ab743c9656f84af586207efd390f16d1`:

- **V01 resolved:** the native decoder includes the enclosing record depth using the same bounded-traversal limit as the encoder. Scalar and empty-array leaves at depths 127/128/129 are covered through both the codec and facade, with signing/compression combinations.
- **V02 resolved:** tier mutations and promotions establish the coherence fence before calling a backend. False returns and exceptions leave upper tiers bypassed; unrelated successful writes cannot restore them. Reads use the authoritative last tier until a complete successful clear. Tests cover real failure control flow, strict/fail-open policy, single/bulk operations, skipped L1 writes, and three-tier promotion.
- **V03 resolved through the explicit history-generation contract:** a new cursor scope is cold-cleared during `ClusterCache::create()` before the runtime is returned, and initialization is recorded only after a successful clear. Restarting an established scope preserves progress. Recreated/restored logs require coordinated rotation to a new, never-used `transportIdentity` and reconciliation of every APCu/L1 domain. Overlapping history IDs are tested with the real PDO transport in SQLite testing mode. Arbitrary same-identity resets remain unsupported; boundary heuristics are not an epoch detector.

Current validation:

- Targeted regression suite: **102 passed, 427 assertions**.
- Prepared-host 34-file integration subset with CLI APCu and isolated Redis/Valkey/Memcached: **402 passed, 2 failed, 1,651 assertions**. The two failures require unavailable Scylla Alternator; four SQL/real-MongoDB test files remain outside this subset. This is not a full matrix pass.
- `composer ic:process` passes. Detailed static checks pass, including PHPStan and Psalm. Normal-host `composer ic:tests:details` and final `composer ic:release:guard` fail at Pest discovery because CLI APCu is disabled. The guard reports zero dependency advisories and one abandoned development package (`doctrine/annotations`).
- Core smoke passes with OPcache off/on on PHP 8.5.4. Runwire certification and soak pass using the installed checkout (temporary copies change only the autoload path; not a fresh consumer installation); soak validates 2,277 requests.
- Sphinx builds with warnings as errors. `git diff --check` passes.

**Pre-tag gate:** commit the final changes and obtain green Security & Standards and Release Verification on that exact revision, including PHP 8.4/8.5 stable/lowest dependencies, all configured real services, independent consumers, and portability checks. Earlier green CI below does not cover this working tree.

## Independent verification of the applied fixes

Verified 2026-09-29 at clean commit `69845554ab743c9656f84af586207efd390f16d1`. The preceding implementation/CI closure records and the original findings below remain historical evidence; this section records the newest independent result.

**All nine original reproductions now pass.** Signed atomic consume returns the stored value once without resurrection on memory, File, PHP-files, SQLite, Redis and Memcached. Redis cleanup preserves the replacement, counter state survives clear, closure cycles return normally, the 4,439,019-byte wide payload is rejected without exhaustion, a false-returning L1 stays fenced, empty history clears local state, authentication traces redact the synthetic secret, and independent memoizer flushes preserve other scopes' results.

The following three cases were reproduced before the working-tree resolution above:

### V01 — P2: accepted depth does not round-trip (F04 boundary)

**Reproduced through `Cache::memory()` with `failOpen=false`.** Build a scalar wrapped in 128 nested arrays. `set('x', $value)` returns true, but the immediate `get('x')` returns a miss. Depths 126 and 127 round-trip in the same probe.

`BoundedValueTraversal` allows this value, while `CachePayloadCodec::unserializeNative()` at `src/Cache/Adapter/CachePayloadCodec.php:322` applies `max_depth=128` to the entire serialized record, including its enclosing array. The accepted value depth and decoder's record depth disagree. The final sweep corrected node-count symmetry but not depth symmetry.

**Required:** account for envelope depth consistently, or reject the value before reporting a successful save. Test encode/decode and facade set/get immediately below, at and above the effective depth limit, with signing and compression variants. A successful save must not create an intrinsically unreadable record.

### V02 — P2: an L1 exception bypasses the coherence fence (F07 failure boundary)

**Reproduced with the same deterministic L1 fixture, changing only its invalidation failure from `false` to an exception.** With `writeToL1=false`, promote `x=old`; write `x=new` to L2 while L1 deletion throws. The facade's default fail-open handling returns false from the write, but subsequent reads still return `old`. The output is `[write=false, read=old, laterRead=old]`; L2 contains `new`.

`TieredCacheAdapter::invalidateSkippedL1()` at `src/Cache/Adapter/TieredCacheAdapter.php:276` updates `l1Readable` only after the fallible deletion returns. Exceptions escape to the facade before the adapter is fenced. Equivalent throwing save/delete/promotion paths need review; the new regression currently forces the private flag to false and therefore does not verify how an actual failure establishes it.

**Required:** fence the affected tier on exceptional exits as well as false returns, preserving error policy. Test real control flow with throwing and false-returning fixtures, single/bulk operations, unrelated successful operations and full-clear recovery. Do not weaken the assertion to accept stale data after an unsuccessful update.

### V03 — conditional recovery gap: recreated history can overlap the old cursor (F05)

**Reproduced with the real PDO transport in SQLite testing mode.** Consume old IDs 1 and 2, retain cached `x=stale`, recreate the event history with new ID 1 invalidating `x` and new IDs 2 and 3 targeting other keys. With the old cursor and transport identity retained, recovery returns false, consume processes only ID 3, and `x` stays stale. Observed state: `cursor=2, oldest=1, newest=3, recovered=false, consumed=1, value=stale`.

`ClusterRecoveryManager` detects a reset only when the new upper boundary is below the old cursor. Once the new history overlaps/passes the cursor, boundary comparisons cannot distinguish its epoch. The added test covers a recreated log that remains behind the cursor only.

**Contract boundary:** the transport-identity documentation already requires a different identity for independent history. A deployment that guarantees identity rotation and a cold clear/rebuild on recreation can exclude this scenario. However, the same-identity automatic-reset recovery tested and described in F05 is only partial. Do not advertise general recreation recovery from these bounds alone.

**Required:** either implement a durable history epoch/fence, or explicitly require coordinated identity rotation plus local cache/cursor reconciliation for recreated logs and test that supported recovery procedure. Add the overlap case so the limits are explicit. Do not claim the current heuristic proves safe replay after arbitrary history reset.

### Verification results for this revision

- [Security & Standards](https://github.com/infocyph/CacheLayer/actions/runs/36526249783) and [Release Verification](https://github.com/infocyph/CacheLayer/actions/runs/36526249302) both succeeded on `6984555`.
- The same 34-file prepared-host subset, with CLI APCu and isolated Redis/Valkey/Memcached services, produced **348 passed, 2 failed, 1,415 assertions**. Both failures still require the unprovisioned Scylla Alternator service; the same four SQL/real-MongoDB files remain outside this subset.
- Normal-host `composer ic:release:guard` still fails at Pest discovery because CLI APCu is disabled. This remains an environment prerequisite, not a newly introduced product regression.
- Core release smoke passed with CLI OPcache disabled and enabled. Updated Runwire certification and soak passed against the installed checkout using temporary copies with only the autoload path adjusted. Certification now reports the expected 3,500 gets and 500 sets; the measurement fix is verified.
- The locked dependency audit reports zero advisories and one abandoned development package, `doctrine/annotations`.
- New local probes: `/tmp/cachelayer40-boundaries.php` (V01/V03) and `/tmp/cachelayer40-throw.php` (V02). Both require the same isolation precautions as the original probes.

**Historical audit outcome:** V01–V03 required remediation. That remediation is now recorded above; final committed-revision CI remains pending.

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

The original F01–F09 release sweep completed on the working branch; the V01–V03 follow-up above still needs final committed-revision CI. Historical substantive sweep head `909f73fb3b57525fcced5e0d024c5a4b92ea9d03` passed Security & Standards #516 and Release Verification #156; the final documentation-only tracker head is reverified before tagging.

| Finding | Remediation status | Evidence |
| --- | --- | --- |
| F01 | **Complete** | Atomic consume paths discard deferred overlays; cross-backend regressions cover repeated consume and no resurrection. |
| F02 | **Complete** | Redis/Valkey clear is restricted to cache data/metadata domains; boundary tests preserve counters, locks and invalidation streams. |
| F03 | **Complete** | Closure fingerprints no longer traverse captures; recursive/self-capture regressions were added. |
| F04 | **Resolved locally, including V01** | Traversal is depth-first and budgeted before descent; decode applies independent bounded traversal to value and tag graphs so the value budget round-trips consistently. |
| F05 | **Resolved locally under the V03 identity-rotation contract** | Recovery handles empty/reset history and retained upper boundaries for PDO and Redis transports. |
| F06 | **Complete** | Redis stale cleanup uses compare-delete and facade tag validation no longer performs unsafe physical deletion. |
| F07 | **Resolved locally, including V02** | Tiered L1 readability is a monotonic fence until full reconciliation/clear. |
| F08 | **Complete** | Redis DSN/authentication credential-bearing parameters are marked sensitive and synthetic-secret regressions cover traces. |
| F09 | **Complete** | Memoizer flushes no longer reset process-global object/closure identities used by other live request scopes. |

Remediation QA closure: the earlier run on `ac5594370c0020ff14be1817bc227c02fb5b117b` exposed stale tagged-read expectations, an outdated Runwire transport fake, F04 round-trip budget asymmetry, and two Pint issues. Those were corrected; exact substantive head `97957893ab1459e365526dab2b913131021489fe` passed Security & Standards #513 and Release Verification #153, and tracker-closure head `4f4e3912bccba11c2ba9e2ef6b1ee60a26c8a2c5` passed #514/#154.

- [x] Correct F01–F05 in the affected production owners and add targeted regressions.
- [x] Correct F06–F09 and add their failure/interleaving regressions.
- [x] Recheck the related original findings in code/tests: R01/R08 traversal, R04/R11 atomic/deferred state, R06/R07 recovery, R10 redaction, R13 tier coherence, R14 counter isolation and Batch 7 request isolation.
- [x] Extend regression coverage across affected backend implementations rather than testing only helper classes.
- [x] Run the configured PHPForge stable/lowest matrix with its declared service prerequisites; keep separate host-only limitations documented rather than disguising missing services as passes.
- [x] Re-run core, independent PSR consumer, Runwire lifecycle/certification/soak, real backend, documentation and configured stable/lowest checks on substantive head `97957893ab1459e365526dab2b913131021489fe`: Security & Standards #513 and Release Verification #153 passed.
- [x] Update plan completion claims after the reopened cases passed exact-head substantive verification. Green CI on `51fcba7` remains historical evidence for the pre-remediation candidate; #513/#153 are the remediation evidence.
- [x] Final sweep corrected tag-budget encode/decode symmetry, Runwire certification metrics/timing, and stale release-contract documentation; substantive head `909f73fb3b57525fcced5e0d024c5a4b92ea9d03` passed Security & Standards #516 and Release Verification #156.

Additional verification improvements: **resolved in the final sweep.** The Runwire certification now reads the nested exported metrics correctly, excludes warmup from the measured RPM denominator, reports warmup separately, and fails if measured get/set counts do not total the configured iteration count. Release Verification #156 recorded 3,500 gets + 500 sets for both baseline and integrated 4,000-iteration workloads. Keep this short CLI workload separate from sustained host-application throughput claims. The configured Deptrac uncovered-dependency count remains visible despite a passing gate and is retained as tooling-coverage follow-up, not hidden by exclusions.

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
