# CacheLayer audit and proposed 4.0.2 release plan

Audit date: 2026-10-30 (Asia/Dhaka). Base revision:
`4322becf18eaec017e8301a372770ec258b298b0`. The changes described below are
uncommitted. The latest published release verified through GitHub is
[4.0.1](https://github.com/infocyph/CacheLayer/releases/tag/4.0.1).

**Decision: local fixes are implemented; release qualification remains open.** Prefer 4.0.2 for the
dependency refresh and compatible correctness fixes. Use 4.1 only if the scope
adds public capabilities. An intentional breaking contract change requires its
own versioning decision; a minor version does not make such a change compatible.

This plan follows the installed PHPForge
[engineering principles](../vendor/infocyph/phpforge/resources/engineering-principles.md):
correctness and security first, existing owners, the smallest compatible diff,
regression evidence, unchanged quality thresholds, and measured sustained host
throughput before performance acceptance. It does not authorize publication.

## Work completed

- Changed the root development requirement and the independent integration
  consumer to `infocyph/runwire:^3.0`.
- Installed published Runwire `3.0`, reference
  `054737bc6a8d4025a8d05fabb8ad390f66ee47ae`. Only Runwire changed in the root
  dependency resolution. The root lockfile is intentionally ignored by the
  existing repository policy; it was refreshed locally, not added to Git.
- Updated the consumer's version assertion to accept stable Runwire 3.x, the
  release-verification job names, README, and current integration documentation.
  The 4.0 release notes and migration guide retain their historical 2.1 references.
- Kept Runwire optional. A clean `--no-dev` installation contains only
  `opis/closure`, `psr/cache`, and `psr/simple-cache`, plus CacheLayer itself.
- Preserved a separate `.gitattributes` workspace edit adding
  `tools export-ignore`, which appeared during the audit.

Follow-up implementation completed F1-F5 below and the additional Redis Cluster
finding F6. The initial reproductions remain recorded for review. Release
qualification, including the unavailable MongoDB environment, remains separate
from source completion.

| Finding | Follow-up status |
| --- | --- |
| F1 Node stale L1 after exceptions | Fixed in existing attempt/copy/cleanup paths; original failures survive cleanup errors |
| F2 Runwire cleanup restores discarded contexts | Fixed with root-context and captured WeakMap identity checks |
| F3 request lifecycle/cancellation | Completed/cancelled/expired requests rejected; scope-free cancellation checked; request can supply its borrowed runtime |
| F4 archive contamination | Explicit Composer exclusions; final archive inspected and installed independently |
| F5 APCu startup instructions | Corrected docs, discovery comments and contributor setup; strict prerequisite remains |
| F6 Redis Cluster pipeline warning | Corrected to per-bucket MULTI; native smoke rejects warnings |

## Review scope and security result

The inventory contains 114 runtime source files and 43 tracked test/support
files. Repository-wide PHPForge checks cover syntax, references, duplicates,
comments, style, architecture, static analysis, and security analysis. Manual
review concentrated on the facade and atomic contracts, all adapter families,
payload and Closure serialization, filesystem trust, locks, counters, Node L1/L2,
cluster publication/cursors/recovery/outbox, memoizers, Runwire contexts and worker
ownership, consumers, workflows, documentation, and package contents.

Current Composer audits returned **zero known advisories** for both the full
locked set and the production-only set. The full set reports the abandoned
development dependency `doctrine/annotations`, reached through
`phpbench/phpbench`; the runtime set has no abandoned packages. All installed
direct dependencies are current according to `composer show --direct --latest`.
This is dependency evidence, not a proof that the library has no vulnerabilities.
No remote exploit was established during this review.

Existing protections checked include bound payload signatures before decoding,
disabled object/Closure payloads by default, bounded payload/decompression and
array traversal, key/namespace validation, parameterized SQL with identifier
validation, token-checked lock release, capability-gated optional integrations,
and host ownership of workers and event loops.

## Findings and remediation

### F1 — P1: Node Cache can serve stale L1 after a throwing mutation

Owner: `src/Node/Adapter/NodeCacheAdapter.php`, especially `save()` at line 236,
`saveItems()` at line 259, and `attempt()` at line 312. Review `clear()`, deletes,
tag updates and read promotions for the same exception ordering.

With `failOpen: false`, L2 is changed first. If the subsequent L1 operation
throws, `attempt()` rethrows before `disableL1()` runs. The old L1 remains
readable. A probe using the existing `FaultingFileCacheAdapter` fixture and
in-memory Node SQLite reproduced:

```text
set key=old
make L1 save throw
set key=new -> CacheBackendException
get key -> old
direct L2 get key -> new
```

This is a consistency defect in the failure path; security impact depends on
what the application caches. Existing Node tests cover a false L1 write result,
but do not cover the throwing fail-closed case.

Implemented approach:

1. Add the failing regression to `tests/Node/NodeCacheTest.php` using the existing
   fault-injection support. Cover single/bulk save, clear/delete/tag operations,
   promotion, false returns, exceptions, and both failure policies where relevant.
2. Disable and clear L1 before an operational exception can escape or turn into a
   fallback. Enclose child-item copying and metadata storage in that same error
   boundary. Cleanup cannot replace the original failure. Keep the fix in the
   existing Node owner without a new hierarchy.
3. Preserve successful-path behavior, deferred-item ownership, TTLs and tags.
   After any partial failure, reads must use valid L2 state or a safe miss.

Acceptance: the regression fails before the fix and passes afterwards; all Node,
cluster and tiered regressions pass; static analysis stays within class/function
cognitive-complexity limits 80/12; measured host throughput meets the release
budget below.

### F2 — P1: Runwire cleanup can restore a released context or throw

Owner: `src/Integration/Runwire/RunwireIntegration.php:134-192`.

`release()` and `bind()` reset the execution-context registry, but an older
`share()` invocation unconditionally restores its captured previous context in
`finally`. Two probes reproduced:

- On the root execution path, release from an inner `share()` restores the old
  outer context: `runtime()` returns the released runtime after inner cleanup.
- Suspend inside nested shares in a Fiber, release the bound runtime from the
  host, then resume the Fiber. Cleanup throws:
  `TypeError: Cannot auto-initialize an array inside property ...::$fiberContexts of type ?WeakMap`.

Implemented approach:

1. Add root and overlapping/nested Fiber regressions for release and rebind
   during a callback, suspension and exception unwinding.
2. Restore the root context only while it is still the current invocation's
   context; restore Fiber entries only while the captured WeakMap remains the
   active registry. Preserve explicit borrowed runtimes and ordinary nesting.
3. Preserve ordinary nesting, Fiber isolation, explicit runtime forwarding,
   no-Runwire fallback, and host ownership. Cleanup must not resurrect discarded
   request state or mask a callback's original exception.

Acceptance: no old context becomes current after release/rebind; suspended
callbacks unwind without registry errors; integration tests, consumer smoke,
certification and soak pass on Runwire 3.0.

### F3 — P2: shared request lifecycle and cancellation checks are incomplete

Owner: `RunwireIntegration::share()`, `checkpoint()`, and
`RunwireExecutionContext::__construct()`.

The integration checks request/runtime identity but accepts a completed request.
It also lets an already cancelled request pass `checkpoint()` when no coroutine
scope was supplied. Both callback executions were reproduced. Runwire 3.0's
`RequestContext` exposes `completed()` and its cancellation token, so the
integration can enforce this boundary without creating a runtime or event loop.

Implemented approach:

1. Add completed, cancelled and expired-request regressions, including requests
   without coroutine scopes and callbacks that mutate cache state.
2. Validate the supplied request before invoking application work; check request
   cancellation/deadline independently of optional coroutine capability.
3. Preserve fresh `RequestContext::create()` usage and explicit runtime
   forwarding. Define the rejection contract and document it. Do not invent an
   activation requirement or a scope-inspection API that Runwire does not expose.

Acceptance: completed/cancelled/expired input cannot execute the protected
callback; cancellation propagates without fallback replay; normal scope-free
and coroutine-assisted calls continue to pass.

### F4 — P2: Composer archives and path consumers include local payloads

Owner: root `composer.json` archive policy, `.gitattributes`, and release consumer
setup. The checked-in Git source archive and a local Composer archive differ.

`composer archive --format=zip --dir=/tmp --file=cachelayer-audit` produced an
archive with 23,102 entries and 439,357,398 uncompressed bytes at the time of the
probe. It included root `vendor/`, `graphify-out/`, local metadata directories,
and generated consumer dependencies. The first path consumer copied about
249 MiB; creating a second consumer copied that generated payload again. A
`--no-dev` path consumer therefore did not demonstrate clean package contents.

`git archive HEAD` excluded untracked vendor/graph data. Consequently this probe
does **not** establish that a published GitHub/Packagist archive leaked these
files. The separate `tools export-ignore` edit helps Git exports but does not
alone define all Composer exclusions.

Implemented approach:

1. Declare explicit Composer archive exclusions for dependencies, generated
   graphs/caches, consumer outputs, local agent/configuration directories and
   development-only payloads. Preserve every tracked runtime file and required
   package metadata.
2. Build and inspect the actual intended distribution. Compare its runtime
   entries against `git ls-files src`; verify no local/development payload enters
   the package. Verify Git exports separately.
3. Resolve fresh consumers outside the working tree or from that artifact, then
   remove and reinstall their locked vendors with `--no-dev`. Run representative
   storage and optional-Runwire smoke checks against the artifact.

Acceptance: a populated development checkout cannot contaminate the package or
consumer; no runtime file is missing; both consumers pass with only their
declared production dependencies.

The final archive has 120 entries, all 114 runtime files, and 144,144 compressed
bytes. Its SHA-256 is
`7da6353353640535c75c4627915f895a97f7e641f2d66f6c8ae756cb18af9fef`.
Runtime file bytes and the packaged manifest were compared with the final tree.
An artifact consumer, installed outside the repository, passed its smoke and
Runwire 3.0 certification/soak checks. This local archive digest does not replace
inspection of the eventual tagged distribution.

### F5 — P2: APCu CLI setup documents the wrong INI key

Owners: `docs/adapters/apcu.rst:13`, APCu test setup and contributor instructions.
The docs and discovery guard use `apcu.enable_cli`; the real setting is
`apc.enable_cli`. The guard's `ini_set()` does not enable CLI APCu. The normal
host quality command fails discovery, while `php -d apc.enable_cli=1 ...` gets
past that prerequisite.

Implemented: correct the documented key and test comments, require the setting at PHP
startup, document the required services and canonical command, and keep the
strict discovery guard. Do not turn missing required services into skipped tests
or alter global host PHP configuration as part of a library fix.

### F6 — P2: Redis Cluster expiring batches request unsupported pipeline mode

Real-cluster verification emitted warnings from phpredis 6.2.0 because
`saveExpiring()` requested pipeline mode. The native client still completed the
original smoke, so a success-only check did not catch the warnings. The upstream
[RedisCluster documentation](https://github.com/phpredis/phpredis/blob/6.2.0/cluster.md#pipelining)
states that pipelining is unsupported and documents MULTI/EXEC transactions.

Changed the existing bucket writer to MULTI mode; every batch already shares one
hash slot. The test client now rejects unsupported modes. Before the fix, the
stricter client suite had 1 failure/16 passes; after it, 17 tests/53 assertions
pass. The real-cluster smoke also passes with warnings converted to exceptions.
Documented the batch behavior without changing public methods or key formats.

## Initial audit evidence, before follow-up fixes

| Check | Current evidence |
| --- | --- |
| PHPForge doctor/config resolution | Healthy; bundled configuration; PHP matrix 8.4/8.5 |
| Composer validate / stable constraints | Passed |
| Locked and production advisory audits | Zero advisories; development abandonment noted above |
| Required `composer ic:process` | Passed with local loopback access for Rector |
| Detailed quality checks after the bump | Syntax: 171 PHP files; references: 124 symbols/3,245 references; comments, Pint, PHPCS, Deptrac, PHPStan, Psalm and Rector passed |
| Duplicate detector | Passed configured gate: 27 groups, 1,165 duplicated lines, 7.08%; no threshold changes |
| Normal `composer ic:tests:details` / `ic:release:guard` | Failed at Pest discovery: `APCu must be enabled for CLI tests.` |
| Full Pest with startup APCu enabled | Failed discovery: required Memcached service unavailable |
| Explicit local test selection with startup APCu enabled | 350 passed, 2 failed, 1,403 assertions; both failures require Scylla Alternator. Included integration, memoization, Node, cluster, serializers and local adapter/security tests. This was not the complete required backend matrix. |
| Root core smoke | Passed with CLI OPcache off and on, PHP 8.5.4 |
| Separate core consumer | Passed |
| Clean `/tmp` copy, `composer install --no-dev --classmap-authoritative` | Passed platform requirements and core smoke; installed package inventory confirms only the three runtime dependencies |
| Runwire 3.0 independent consumer smoke | Passed |
| Existing Runwire certification | Passed 4,000 operations, 250 warmups, no errors, no measured memory delta; integrated/unintegrated component RPM ratio approximately 0.901 |
| Existing Runwire soak | Passed 2,048 sequential/256 concurrent requests, 2,277 validated requests, 10 intentional failures, 9 cancellations, 8 deadlines; allocated-memory growth 8 MiB, at its existing limit |
| Quick PHPBench | 30 subjects, zero failures/errors; supporting component measurements only |
| Sphinx `-W --keep-going` | Passed from a temporary uv environment |
| Git whitespace check | Passed |

The existing certification's 0.50 RPM-ratio floor compares integrated and
unintegrated component calls. Its result is neither a 2.1-to-3.0 release
regression measurement nor production HTTP RPM evidence. The soak is request
count bounded, not a sustained-duration production soak.

The original host Python lacked Sphinx and ensurepip; temporary uv tooling
resolved the documentation prerequisite without changing the host. Generated
consumer vendors/locks were removed after validation. One analyzer attempt was
stopped because those generated nested vendors contaminated its scope; the
subsequent clean-tree run produced the detailed results above.

## Follow-up verification

- Focused Node/Runwire/cluster/tiered/Redis Cluster validation: **160 passed,
  744 assertions**. This includes mutation and promotion failures under both
  policies, tag storage/rotation, item-copy failures, cleanup-error preservation,
  nested release/rebind, suspended Fiber exceptions and request lifecycle checks.
- With isolated cached services, all available-backend tests passed: initially
  **461 passed, 1,861 assertions**, excluding only the real MongoDB file. The
  complete gate with a MongoDB endpoint configured reached the same 461 passes
  and **two real MongoDB connection failures**. Eight final promotion regressions
  were then added. Both final `ic:tests:details` and `ic:release:guard` reached
  **469 passed, two failed, 1,885 assertions**. The only failures remain the two
  real MongoDB connection cases; the release guard exits nonzero.
- Real MySQL/MariaDB identity/collation and transaction-publication tests,
  PostgreSQL, Redis/Valkey, Memcached and Scylla Alternator checks passed.
- Real three-node Redis Cluster smoke passed with warnings treated as failures.
- Independent upstream PSR suites against the archive: **338 tests, 469
  assertions, two existing long-key skips** from the unchanged CI fixtures. Those
  established interoperability exceptions are distinct from the required Pest
  matrix, whose strict policy remains unchanged.
- PHP 8.5.4 core smoke passed with OPcache off/on. The archive's production
  consumer also passed in the cached PHP 8.4 container. This PHP 8.4 smoke is not
  the full PHP 8.4 dependency/backend matrix.
- The final archive consumer passed Runwire 3.0 certification and soak: no
  certification errors, no pending invalidations, 2,277 validated soak requests,
  and 8 MiB allocated-memory growth. The final component RPM ratio was about
  0.906 against the unintegrated path; no old-release host RPM claim is made.
- Quick PHPBench on the fixed sources passed **30 subjects, zero errors or
  failures**. Final Sphinx documentation builds passed with warnings as errors.
- PHPForge processors, syntax, references, comments, duplicates, style,
  architecture, maximum-level PHPStan, Psalm and Rector passed after the runtime
  fixes. The active complexity limits remain 80/12.

MongoDB is an environment blocker, not a demonstrated CacheLayer regression:
the configured MongoDB image exits before accepting connections and reports:
`Linux kernel versions 6.19 and newer has a known incompatibility with this
version of MongoDB` (SERVER-121912). Its compatibility check was not bypassed,
no backend was substituted, and no required test was skipped to pass a gate.
The Docker project is isolated as `cachelayer_audit_20261030`; temporary services
are removed after validation. Native Scylla CQL, Windows, lowest dependencies,
the complete PHP 8.4 matrix and production host throughput still require the
release environment/hosted jobs.

## Release validation plan

1. Retain the implemented F1-F6 fixes and failing-before/passing-after
   regressions in the candidate. Keep
   changes within existing cohesive owners and preserve public parameter names,
   storage formats, PSR behavior and host runtime ownership.
2. Run focused Node/Runwire/package tests during implementation, then the
   required PHPForge flow: doctor/config review, `ic:process`,
   `ic:tests:details`, and `ic:release:guard`. Keep all detectors, paths and
   thresholds active; do not add suppressions, baselines or skipped tests.
3. Resolve the host/image MongoDB incompatibility and execute the complete
   backend suite again. Live MySQL/MariaDB, PostgreSQL, Redis/Valkey, Memcached,
   Redis Cluster and Scylla Alternator have local evidence. Real MongoDB and
   native Scylla CQL remain open. Test doubles and SQLite do not substitute for
   those remaining production engines.
4. Re-run existing release jobs on PHP 8.4 and 8.5, lowest/stable Runwire
   dependencies, Windows/Linux core smoke, independent PSR contracts, real Redis
   Cluster/CQL, production consumers, and docs. Record unavailable native runtime
   capabilities and any next intended PHP target separately.
5. Before accepting performance-sensitive fixes, collect matched baseline and
   candidate trials on the same stable production-equivalent host. Compare the
   old/new release with equivalent features and validated results. Use several
   warmed trials and concurrency levels for hit/miss/fill, tagged and bulk calls,
   lock contention, Node/cluster invalidation and persistent request isolation.
   Capture successful RPM/RPS, variance, p50/p95/p99, errors/timeouts, CPU,
   continuously sampled process-tree RSS, connections and queue growth. Start
   measurements after readiness; include a sustained soak with lifecycle cleanup.
   Use the principles' default maximum **2% median successful-RPM regression**
   for stable critical workloads. Investigate noise with unchanged-revision
   reruns; do not tighten the existing component test blindly or relax a gate.
6. Commit the reviewed candidate and obtain hosted evidence for that exact SHA.
   Before tagging, inspect the final artifact and repeat the artifact consumer
   smoke checks. Publish only after the required gates are green.

Hosted evidence currently applies only to the unchanged base revision: seven
associated workflow runs succeeded, including
[Security & Standards](https://github.com/infocyph/CacheLayer/actions/runs/40158043693)
and [Release Verification](https://github.com/infocyph/CacheLayer/actions/runs/38780089343).
Those runs do not validate this uncommitted dependency bump and runtime fixes.
No commit, push, tag or release was created during this audit.

## Deferred improvements

- Address `doctrine/annotations` through the owning PHPForge/PHPBench dependency
  chain when an upstream-compatible solution exists; do not remove benchmarks or
  pin an unrelated local workaround.
- Review measured hot paths before changing metrics defaults, batching, codecs,
  memoizer limits or adapter structure. A passing duplicate detector does not
  justify consolidating distinct backend protocols solely to reduce line count.
- The Runwire documentation now states the actual lock-provider boundary:
  File/PDO/polling helpers call `usleep()` directly. Consider cooperative waits with
  contention/cancellation tests and throughput measurements. Such integration
  must propagate cancellation instead of entering a fail-open resolver fallback.

## Local evidence locations

Audit-session logs are under `/tmp/cachelayer-*` and are ephemeral. Key files:
`cachelayer-final-details-clean.log`, `cachelayer-release-guard.log`,
`cachelayer-local-pest.log`, `cachelayer-probe-results.log`,
`cachelayer-runwire-{update,smoke,certify,soak}.log`,
`cachelayer-production-{install,platform,smoke}.log`, `cachelayer-bench.log`,
`cachelayer-docs.log`, `cachelayer-audit.json`, and
`cachelayer-runtime-audit.json`. The executable failure probes are in
`/tmp/cachelayer-audit-probes.php`; their scenarios and expected regressions are
recorded above so the plan remains useful after temporary files expire.

Final follow-up logs use `cachelayer-completed-{process,details,guard}.log`,
`cachelayer-final-focused.log`, `cachelayer-candidate-{certify,soak,bench,docs}.log`,
`cachelayer-redis-cluster-fixed.log`, `cachelayer-psr-tests.log`,
`cachelayer-php84-consumer.log`, and `cachelayer-services-up.log`.
