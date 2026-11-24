# CacheLayer CI recovery and codebase hardening

## Baseline and scope

Latest scheduled run: https://github.com/infocyph/CacheLayer/actions/runs/44988885781 (November 22, 2026). Same revision passed November 1, then failed November 8, 15 and 22. Five failed jobs, ten passed, one skipped. PHPStan 480 (PHP 8.4) / 481 (8.6); Psalm 1,356 on both inspected runtimes; PHP 8.6 QA: 468 passed, three deprecations (1,898 assertions) on both dependency lanes. These are diagnostic counts, not unique defects.

Preserve PSR-6/16, public APIs, storage and event wire values, backend semantics, atomicity, fail-open/fail-closed behavior and performance. No weakened gates, new suppressions, baselines or exclusions; no SHA pins in dependency/workflow declarations. Keep composer.lock untracked. GitHub-hosted verification only, no merge. One batch = one commit; group related files, target 50–100 when justified. Update this tracker with each batch; distinguish implemented and verified.

## Batches
- [x] B01 implementation: 71 analyzer-reported Override attributes in eight source files; verified declaration positions before edits. Expand three existing release smoke/consumer PHP matrices to 8.6. Hosted Release Verification: 19/19 jobs passed; Security & Standards still failed on the same five jobs. Psalm fell from 1,356 to 1,285 on PHP 8.4.
- [x] B02a implementation: remaining 174 analyzer-reported source override declarations in 23 files; expand independent PSR and real Redis Cluster matrices to 8.6 and Scylla to 8.5 (no published PHP 8.6 driver). Release Verification passed 24/24.
- [x] B02b implementation: widen PSR-6 bulk key-array contracts; normalize associative input at native backend boundaries; add tag-generation return contracts; make file/PHP-file clear operate on literal directories and preserve metadata lock files; guard MongoDB normalized row nullability. Added four regression tests. Release Verification passed 24/24 jobs. Security & Standards pending.
- [x] B02c implementation: reject nonfinite wait/lease values and overflowing native TTL conversions; enforce file-lock token ownership; preserve named argument bindings in memoization keys; fix closure fingerprint narrowing, redundant callable branches, cluster exception construction, and explicit filesystem resolution. Added three regression tests and strengthened the MongoDB associative-key test double. Release Verification passed 24/24.
- [x] B02d implementation: remediate B02b analyzer regressions in backend key-list normalization, filesystem iterator typing and MongoDB row normalization; reconcile numeric-string tag map contracts across all adapters and fix PDO numeric-tag initialization. Added numeric tag identity regression test. Release Verification passed 24/24.
- [x] B02-platform implementation: Runwire ^3.1 in development and independent consumer; align documentation and workflow labels; ubuntu-latest for every GitHub Ubuntu runner. Retain PHP >=8.4 as library floor and existing full PHP 8.4/8.5/8.6 matrices; Scylla native driver lacks 8.6 binary. Read the Docs requires explicit OS image, not GitHub runner labels. Hosted verification pending.
- [x] B02e implementation: correct two hosted Pint findings; improve memoizer type narrowing, bulk tag-map contracts and facade normalization; fail explicitly when shared-memory initialization fails and commit deferred writes before detaching. Hosted verification pending.
- [x] B02-platform follow-up: use upstream Ubuntu-specific Scylla driver binary selected from actual runner OS release and PHP version. Generic manylinux artifact failed with missing libssl.so.1.1 on ubuntu-latest (26.04); no legacy SSL packages installed. Hosted verification pending.
- [x] B02f implementation: document precise exception contracts across 16 input, factory, event, serializer, validation and atomic support files; validate direct event IDs and reject explicitly invalid tier namespaces and nonfinite/negative timeouts before backend initialization. Added two regression tests. Hosted verification pending.
- [ ] B02g: remaining source analyzer contracts: throws, optional extension types, mixed values, internal visibility, return/parameter types, taint and other source findings.
- [ ] B03: test/benchmark/tool analysis, Pest dynamic context and benchmark discovery without weakening coverage or assertions.
- [ ] B04: PHP 8.6 compatibility. MongoDB library 2.5.1 reports is_integer() deprecation in Operation/Find.php:182 via findOne; explicitly out of remediation scope per owner on 2026-11-24. Record as external blocker; keep real MongoDB coverage and deprecation gates intact. InvalidationEventType::Namespace triggers deprecated enum declaration; inspect public usage and serialized enum compatibility. PHP 8.6 also deprecates a same-named class constant alias (https://www.php.net/manual/en/migration86.deprecated.php). A rename may break public consumers and PHP serialized enum payloads: explicit breaking-contract decision required. Keep wire value namespace unchanged. No vendor patching or suppression. Review remaining runtime/version references and Scylla driver binary availability.
- [ ] B05: source review: adapters, facade, atomic operations, locks, counters, codecs, serializer, memoization and support utilities.
- [ ] B06: Node, Cluster, invalidation transport/cursor/outbox/recovery and Runwire integration (including 3.1); concurrency, failure containment, lifecycle and real backend coverage.
- [ ] B07: docs, examples, Composer, CI, packaging, release scripts, public contracts and structural consolidation; avoid speculative architecture or API churn.
- [ ] B08: acceptance across PHP 8.4–8.6, lowest/stable, independent PSR contracts, real services, clean consumer, docs, security, Runwire soak, scoped performance evidence and bot findings.

## PHPForge coverage register

Authority: https://github.com/infocyph/PHPForge/blob/main/resources/engineering-principles.md. Initial register, not a completed audit. Record individual rule IDs and evidence for actionable findings.

| Rule / ruling | Boundary | Evidence required | Status |
| --- | --- | --- | --- |
| CORE-01/02: contracts and simplicity | All source | API diff and PSR suites | Pending |
| FLOW-01/02: root-cause fixes and full gates | CI and analyzers | Hosted results and unchanged scan scope | B01 implemented, CI pending |
| PERF-01: sustained successful throughput | Hot paths | Matched measurements when touched | Pending |
| COMPAT: runtime and contracts | PHP, PSR, drivers, event wire | Runtime matrices, compatibility tests | B01 and B02a expanded matrices, B04 pending |
| TYPE: validation and explicit types | Backend payloads, options, IDs | Negative tests and analyzers | Pending |
| STRUCT: cohesion and abstraction | Adapters, node/cluster, locks | Call graph and justified consolidation | Pending |
| REQUEST: loading and serialization | Facade, codecs, closures | Cold/warm paths and bounded decoding | Pending |
| MEM: bounded resources | Bulk/deferred/local caches | Boundary tests and memory evidence | Pending |
| SEC: trust and crypto | Payloads, SQL, files, credentials | Tamper tests, secret containment | Pending |
| DATA: external/cache correctness | TTL, CAS, locks, invalidation | Real services and contention tests | Pending |
| OBS: low-cost diagnostics | Metrics, health | Failure and disabled instrumentation tests | Pending |
| CONFIG-01/02/03: dependency and loading | Composer, export, CI | Clean consumer, lowest/stable, independent PSR | Pending |
| OPS: lifecycle and release | Workers, recovery, release | Soak/restart/hosted release evidence | Pending |

## Verification
- Baseline workflow logs reviewed; five failed jobs confirmed.
- B01: all 71 declarations matched; hosted release run https://github.com/infocyph/CacheLayer/actions/runs/45330742401 passed all 19 jobs. Security run https://github.com/infocyph/CacheLayer/actions/runs/45330742716 still has 5 failed jobs; PHP 8.4 analysis has 480 PHPStan and 1,285 Psalm findings. Existing reusable workflow already follows @main and covers 8.6.
- B02a: all 174 remaining source override positions matched, three further release matrices expanded. Release Verification https://github.com/infocyph/CacheLayer/actions/runs/45331382698 passed 24/24; PHP 8.4 analysis reported 480 PHPStan and 1,111 Psalm findings before superseded Security & Standards run was cancelled. ScyllaDB driver v1.5.1 publishes PHP 8.4/8.5 binaries but no PHP 8.6 asset (https://github.com/he4rt/scylladb-php-driver/releases/tag/v1.5.1). Hosted verification pending.
- Full codebase inventory obtained; review incomplete.

- B02b: CORE-01, COMPAT-02, TYPE-01, DATA-01: PSR bulk methods accept arrays with arbitrary indices; BSON `$in` and positional SQLite placeholders need lists. SEC/DATA: glob interpreted metacharacters in configured cache paths, which could clear a neighboring store or leave entries behind; directory iteration with exact suffixes preserves metadata lock identities. Regression tests added; hosted Release Verification https://github.com/infocyph/CacheLayer/actions/runs/45331581131 passed 24/24; main quality suite pending.

- B02c: TYPE-01, SEC, DATA and OPS: reject invalid durations before lock I/O, require matching ownership token to release or refresh file locks; preserve argument keys in memoization cache identity. COMPAT-02: named argument bindings retained. All changes and tests awaiting hosted verification.

- B02b analysis (PHP 8.4): 493 PHPStan / 1,086 Psalm findings; PHPStan increase attributable to key-list, filesystem iterator and MongoDB complexity findings, addressed in B02d. https://github.com/infocyph/CacheLayer/actions/runs/45331581710.
- B02d: TYPE-01, DATA and COMPAT: PHP array keys coerce canonical decimal strings to int; preserve tag identity at SQL boundary and accurately declare generation maps. No change to tag wire/storage format. DirectoryIterator avoids glob and ambiguous iterator return type. Regression coverage and hosted verification pending.

- B02-platform: latest Runwire release 3.1 confirmed; latest Scylla driver release still has no PHP 8.6 assets. B02c and B02d release runs https://github.com/infocyph/CacheLayer/actions/runs/45331857300 and https://github.com/infocyph/CacheLayer/actions/runs/45332094864 passed 24/24 each. Retain no-dev optional Runwire contract and use version range rather than revision pin. Ubuntu latest uses libuv1-dev to resolve the distribution runtime dependency across t64 transition.

- B02d hosted QA evidence: PHP 8.6 lowest lane 476 passed, 3 deprecated, 1,951 assertions. The two new Pint failures (NullCacheAdapter blank line and SecuresFilesystemDirectories method ordering) are fixed in B02e. Remaining deprecations: upstream MongoDB (2) and reserved enum case (1). B02e includes deferred shared-memory lifecycle regression coverage. Hosted verification pending.

- B02-platform verification: Runwire 3.1 stable and lowest consumer lanes on PHP 8.4/8.5/8.6 passed; Scylla generic manylinux binary failed on Ubuntu 26.04 due to OpenSSL 1.1 linkage in run https://github.com/infocyph/CacheLayer/actions/runs/45332480670. Upstream latest release includes native Ubuntu 24.04/26.04 binaries for PHP 8.4/8.5; follow-up selects matching OS asset instead. PHP 8.6 native binary remains unavailable.

- B02f: TYPE-01 and FLOW-02: document actual explicit exceptions and verified propagation rather than suppressing MissingThrowsDocblock. Cluster event constructors previously bypassed the event ID validation applied by withId(); validation now runs at construction. Tier descriptors previously silently substituted default namespace on invalid explicit configuration and allowed nonfinite/negative timeouts; both now fail with the existing argument exception. No storage format changes. Hosted verification pending.
