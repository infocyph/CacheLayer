# CacheLayer — production feedback and upstream action plan

Status: **open — initial investigation and release planning** · 2026-11-26

Baseline: 4.0.3 (full-run install evidence). Release target to be selected after root-cause isolation; no version bump authorized by this file.

## Findings

- [ ] **CACHE-001a (P1 candidate):** [PDO bulk](https://github.com/infocyph/infbyte-app-experiment/actions/runs/45661843498/job/139376700995) Bulk value or miss semantics differ. Capture exact values/types including null/false/zero/default/miss, native stored rows, serialization and expiration. Compare PSR-6/16 and bulk vs single semantics on SQLite/MySQL/PostgreSQL/MariaDB. No false hit or namespace crossover.
- [ ] **CACHE-001b (P1 candidate):** [Memcached concurrent insert](https://github.com/infocyph/infbyte-app-experiment/actions/runs/45661843498/job/139376700138) Concurrent insertion did not elect one winner; [mixed atomic](https://github.com/infocyph/infbyte-app-experiment/actions/runs/45661843498/job/139376696779), [PDO atomic](https://github.com/infocyph/infbyte-app-experiment/actions/runs/45661843498/job/139376694114). Verify atomic add/CAS/consume, token encoding, lock owner/TTL and per-process cache configuration; independent persisted winner count and stale owner rejection.
- [ ] **CACHE-002 (P1 candidate):** [SQLite stampede](https://github.com/infocyph/infbyte-app-experiment/actions/runs/45661843498/job/139376756649) race worker failed; [PDO stampede](https://github.com/infocyph/infbyte-app-experiment/actions/runs/45661843498/job/139376759642). Retain child exit/stderr and native lock/transaction evidence; one compute callback per cold generation, expiration/reacquisition, bounded contention and cleanup.
- [ ] **CACHE-003 (P1 candidate):** MongoDB TLS restart/trust/atomic [hosted job](https://github.com/infocyph/infbyte-app-experiment/actions/runs/45661843498/job/139376695876), [hosted job](https://github.com/infocyph/infbyte-app-experiment/actions/runs/45661843498/job/139376700867), [hosted job](https://github.com/infocyph/infbyte-app-experiment/actions/runs/45661843498/job/139376701801), outage closed/open [hosted job](https://github.com/infocyph/infbyte-app-experiment/actions/runs/45661843498/job/139376759925), [hosted job](https://github.com/infocyph/infbyte-app-experiment/actions/runs/45661843498/job/139376761906). Differentiate driver/fixture startup from incorrect failure semantics; never accept unverified TLS; authoritative fail closed, optional fail open with observable state.
- [ ] **CACHE-004 (P2 retest):** earlier Redis Cluster/Scylla failures include native extension/startup; latest Scylla and Redis TLS controls pass. Requalify remote capabilities, atomicity, bulk, TTL, remember, tags, metrics, payload policy, node invalidation/reconnect and loss of majority. Do not assign corrected setup failures upstream.
- [ ] **CACHE-005 (P1 integration):** OTP state lock [hosted job](https://github.com/infocyph/infbyte-app-experiment/actions/runs/45661843498/job/139376679657) and Foundation risk concurrent PDO [hosted job](https://github.com/infocyph/infbyte-app-experiment/actions/runs/45661843498/job/139376681906); compare exact native lock and advertised capability semantics. See [OTP](otp.md), [Foundation](foundation.md). Confirm cache ownership before changing implementation.

## Batches
A: PDO value/atomic contracts; B: Memcached and shared lock races; C: MongoDB, Redis Cluster, Scylla and invalidation; D: OTP/risk/query-cache integration and all 197 cache tuples; retain positive file/PHP-files/Redis controls.

## Evidence and execution rules

Baseline: [run 45661843498](https://github.com/infocyph/infbyte-app-experiment/actions/runs/45661843498) (PHP 8.5, c5c5efa; 1,505/2,179 jobs passed), [run 45756151480](https://github.com/infocyph/infbyte-app-experiment/actions/runs/45756151480) (6f73868; 45/54 passed, all 14 worker types operational), [defect register](../qa/production-defect-register.md), [capability inventory](../qa/capability-coverage.md). All 22 full-run job-summary pages were screened, representative logs reviewed, and existing source-traced findings carried forward. Counts and scenario names are not root-cause counts. Exact installed versions not explicitly established below must be taken from each job's package inventory. This is an initial upstream handoff, not completed root-cause or release qualification. Do not treat an uninvestigated failure as a library defect, or absence of a confirmed finding as proof of defect-free behavior.

Follow [PHPForge engineering principles](https://github.com/infocyph/PHPForge/blob/main/resources/engineering-principles.md); use GitHub-hosted workers, one logical batch per commit, update tracker with exact source/version/job evidence. No skips, vendor edits, weakened assertions, security overrides, dependency revision pins, or automatic merge. Keep frozen 2,177 routine tuples and two native gRPC modes; add only justified regression cases. Run published-package consumer tests on PHP 8.5 and supported upstream CI versions; final full matrix after upstream releases. Keep fixture/third-party fixes separate. No upstream implementation performed in this feedback batch.

## Upstream execution tracker

- [ ] A — PDO value and atomic contracts
- [ ] B — Memcached and shared lock races
- [ ] C — remote adapters and invalidation
- [ ] D — consumer integration and qualification
- [ ] E — Runwire 4.0.1 migration, applicable dependency and workflow versions, QA findings and documentation
- [ ] Final hosted CI and PR review feedback

Source: infocyph/infbyte-app-experiment `feedbacks/cachelayer.md` blob `4284ac401208f89c4e3ecba6a8a708102b7cea75`. No findings are closed until revision-specific evidence is available.

### Batch E1 — dependency and current QA findings

- Implementation submitted; hosted verification pending. Runwire constraint and release consumer gate raised to 4.0.1; optional runtime ownership preserved. Existing compatible package ranges remain in place pending inventory verification.
- Baseline [run 45944261801](https://github.com/infocyph/CacheLayer/actions/runs/45944261801): QA/benchmarks passed; Psalm failed on 8.4/8.5/8.6 (16 findings on 8.4 and 8.6). Removed obsolete Redis suppression; declared filter and optional pcntl; moved publisher input from argv to a pipe; constrained Alternator to local fixtures without redirects; corrected always-throwing test return type.
- EXEC-01/02, CORE-01, FLOW-02, COMPAT, SEC, CONFIG, OPS: preserve runtime API and contracts, no new skips or analyzer overrides; verify actual supported PHP versions, hosted QA, analysis, release smoke, and consumer soak. Remaining production findings and performance qualification are open.

### Batch A/B1 — PDO schema startup and binary transport

- Source-confirmed PostgreSQL initialization race: production stampede job 139376759642 and Foundation risk job 139376681906 report duplicate `pg_type_typname_nsp_index` and `pg_class_relname_nsp_index` entries during concurrent DDL. Serialize PostgreSQL schema creation by transaction-scoped advisory lock; preserve caller transaction ownership. SQLite busy timeout now precedes WAL negotiation. SQLite failure details remain incomplete.
- CACHE-001a workload includes binary `a\\0\\xffb`, null, zero and false. PostgreSQL BYTEA write paths previously used untyped execute arrays. Bind payloads as LOB on bulk, single and atomic insert/update. Add strict value/absence tests on SQLite, MySQL and PostgreSQL and binary atomic round trips. Exact production failing value still requires hosted replay.
- CORE-01/DATA/SEC/PERF/OPS: no schema format change, no retry of ambiguous writes, no weakened assertions. Hosted validation and independent concurrency reproduction pending. Keep findings open until confirmed.

### Batch E2 — release verification feedback

- Runwire 4.0.1 release consumer: all six lowest/stable jobs passed (PHP 8.4/8.5/8.6) on commit `7d69d2982b418541c2bc5d0794140ab42af35e6e`, [run 45944749434](https://github.com/infocyph/CacheLayer/actions/runs/45944749434). Core, PSR, Redis Cluster and Scylla real-service jobs also passed. Documentation failed solely on heading widths after version text update; corrected headings without relaxing Sphinx `-W`. Final verification on new head is still required.
- PostgreSQL bulk failure confirmed as pgsql by production job 139376700995; no claim of closure until the strict binary regression passes. Production job 139376694114 had a MySQL connection loss, distinct from the catalog creation race; investigate process ownership rather than blindly retry writes.

- Docs dependency upgraded to Sphinx 9.x (9.2.0 published stable as of this check); retain other compatible ranges and validate theme/extensions in the hosted docs gate.
