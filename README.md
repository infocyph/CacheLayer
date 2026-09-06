# CacheLayer

[![Security & Standards](https://github.com/infocyph/CacheLayer/actions/workflows/security-standards.yml/badge.svg)](https://github.com/infocyph/CacheLayer/actions/workflows/security-standards.yml)
![Packagist Downloads](https://img.shields.io/packagist/dt/infocyph/CacheLayer?color=green)
[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](https://opensource.org/licenses/MIT)
![Packagist Version](https://img.shields.io/packagist/v/infocyph/CacheLayer)
![Packagist PHP Version](https://img.shields.io/packagist/dependency-v/infocyph/CacheLayer/php)

CacheLayer is a PHP 8.3+ caching toolkit built around four deliberately separate concerns:

```text
CacheLayer
├── Cache
│   ├── PSR-6 and PSR-16
│   ├── generation-tagged records
│   ├── bounded stampede protection
│   ├── optional atomic coordination
│   └── tiering
├── Node Cache
│   └── APCu L1 → SQLite L2
├── Cluster Cache
│   └── durable invalidation between Node Caches
├── Atomic Counters
└── Process-local Memoization
```

The ordinary cache is disposable storage. Cluster Cache distributes invalidations, not values. Atomic cache coordination is an optional backend capability for conditional claim/replace/consume workflows; unsupported stores return no capability rather than emulating it. Atomic counters remain outside the cache contract because numeric mutation requires a different stronger contract. Memoization stays process-local.

## Installation

```bash
composer require infocyph/cachelayer
```

Choose extensions and client packages only for the backends you use: APCu, Redis/Valkey, Memcached, PDO, SysV shared memory, MongoDB, or Cassandra/ScyllaDB.

## Cache

```php
use Infocyph\CacheLayer\Cache\Cache;

$cache = Cache::sqlite('app', '/var/cache/my-app/cache.sqlite');

$cache->setMultiple([
    'profile.1' => ['name' => 'Ada'],
    'profile.2' => ['name' => 'Grace'],
], 300);

$profiles = $cache->getMultiple(['profile.1', 'profile.2', 'profile.3']);
$cache->deleteMultiple(['profile.1', 'profile.2']);
```

`Cache` implements PSR-6, PSR-16, `ArrayAccess`, and capability-provider interfaces. It intentionally does not implement `Countable`, magic property access, runtime namespace mutation, or compatibility aliases. Keys and tags must be 1–64 characters and match `[A-Za-z0-9_.-]+`; invalid bulk input is rejected before storage is changed.

Namespaces are configured separately from logical keys. Do not encode a namespace as `namespace:key`: `:` is reserved by PSR-6/PSR-16 and remains invalid at CacheLayer's public key boundary. For example:

```php
$cache = Cache::redis('mytm', client: $redis);
$cache->set('user', $user); // namespace = mytm, logical key = user
```

Adapters map that pair into their own internal metadata/data key space. The internal physical representation is an implementation detail and must not be supplied as a public cache key.

A callable passed as the PSR-16 `get()` default is returned as a value. Use the explicit `remember()` API to compute and persist a miss:

```php
$user = $cache->remember(
    'user.42',
    fn () => $repository->find(42),
    ttl: 300,
    tags: ['users'],
);
```

`remember()` follows get → miss → lock → recheck → resolve → save → release. Lock waiting is bounded; a timeout computes fail-open and records the unlocked computation. No lock operation occurs on a hit.

## Atomic cache coordination

Atomic operations are an optional capability, not part of PSR-6/PSR-16 or the base `CacheInterface`:

```php
$atomic = $cache->atomic();
if ($atomic === null) {
    throw new RuntimeException('Selected cache backend cannot coordinate atomically.');
}

// Exactly one concurrent claimant can create a live claim.
if (!$atomic->setIfAbsent('webhook.claim.42', true, 300)) {
    // Already claimed, or a fail-open backend failure returned the fallback.
}

// Replace only the existing live value that strictly matches with PHP ===.
$advanced = $atomic->compareAndSet('workflow.state.42', 'pending', 'running', 300);

// One caller receives and consumes this state.
$state = $atomic->getAndDelete('oauth.state.42');
```

`setIfAbsent()` stores the encoded value and TTL as one conditional backend operation. `compareAndSet()` replaces an existing live logical value only when the decoded value matches the expected value with PHP strict equality (`===`); absence is distinct from a cached `null`, so use `setIfAbsent()` when absence is the condition. `getAndDelete()` returns and consumes one live value atomically. CacheLayer never emulates these primitives with public `has()/get()` plus `set()/delete()` calls. Atomic replacement/claim TTL accepts relative seconds, `DateInterval`, or an absolute `DateTimeInterface`; a non-positive resolved TTL is a no-op and returns `false`.

| Backend | Atomic cache coordination | Scope |
|---|---|---|
| Array memory | Yes | one PHP process |
| Shared memory | Yes | one host / shared SysV segment |
| Redis / Valkey | Yes | supplied authoritative Redis-compatible store |
| Redis Cluster | Yes | stable CacheLayer hash-slot bucket |
| MongoDB | Yes | supplied authoritative collection |
| APCu / Memcached | No | no full atomic consume primitive |
| PDO / SQLite | No | no cross-driver atomic contract in 3.3 |
| File / PHP files | No | ordinary writers do not share one atomic key lock |
| ScyllaDB | No | no full three-operation contract exposed |
| WeakMap / Null / Tiered | No | not one authoritative coordination domain |

Use dedicated, untagged keys for portable replay claims, nonces, challenges, state transitions, and one-time state. Tag rotation is a separate invalidation mechanism and is not part of the portable atomic linearization boundary. Redis/Valkey, Redis Cluster, and MongoDB therefore reject `compareAndSet()` on tagged records. Array memory and SharedMemory can validate tag generations inside their own local atomic domain, but callers should not depend on tagged CAS when code must be portable across backends. Tiered caches remain non-atomic even when an individual tier supports the capability.

For security-sensitive coordination, use `failOpen: false` when the caller must distinguish a backend outage from a normal conditional miss. With fail-open enabled, `setIfAbsent()` and `compareAndSet()` fall back to `false`, `getAndDelete()` returns the supplied default, and `backend_failure` is recorded.

## Tags and expiration

```php
$cache->setTagged('article.7', $article, ['articles', 'author.12'], 600);
$cache->invalidateTags(['articles', 'author.12']);
```

Each tagged record embeds its complete snapshot of opaque 128-bit tag generations. Invalidation replaces each generation, and reads fetch all required generations in a batch. A missing or mismatched generation makes the complete record stale, so lost metadata cannot resurrect an older record. There are no per-entry reverse tag indexes or partially tagged writes.

Zero and negative PSR-16 TTLs delete the key. Namespaces are validated—not normalized—and must be 1–64 characters matching `[A-Za-z0-9_.-]+`.

## Native bulk paths

Bulk methods validate once and call the adapter’s native bulk contract. Deferred PSR-6 items are also persisted through the same bulk path on `commit()`.

| Backend | Bulk read/write strategy |
|---|---|
| Array memory | direct array lookup/update |
| WeakMap | one prune pass plus direct lookup |
| Null store | immediate misses/no-op writes |
| APCu | array `apcu_fetch`, grouped stores |
| Redis / Valkey | `MGET`, `MSET`, pipelined TTL writes |
| Memcached | `getMulti`, TTL-grouped `setMulti` |
| PDO | chunked `IN (...)`, multi-row upsert |
| MongoDB | `$in`, `bulkWrite` |
| ScyllaDB | partition-bucketed `IN`, bounded unlogged batches |
| Shared memory | one lock per batch operation |
| File / PHP files | optimized sequential filesystem access |
| Redis Cluster | fixed hash buckets and same-slot grouped operations |

Redis Cluster uses 128 stable bucket hash tags. Memcached and Redis Cluster clear a namespace by replacing opaque namespace/bucket generations, so they do not scan, flush other namespaces, or maintain a permanent key membership index.

## Adapters

The public factories are:

```php
Cache::memory();       Cache::weakMap();      Cache::nullStore();
Cache::apcu();         Cache::file();         Cache::phpFiles();
Cache::sharedMemory(); Cache::redis();        Cache::valkey();
Cache::redisCluster(); Cache::memcached();    Cache::pdo();
Cache::sqlite();       Cache::mongodb();      Cache::scylla();
Cache::tiered([...]);
```

Data and internal metadata use physically separate key spaces. Adapters are public for PSR-6 use, but tagging, stampede protection, policy-aware error handling, atomic capability discovery, and metrics are facade responsibilities; use `Cache` for consistent CacheLayer semantics. SQL-like stores can install schema explicitly with `PdoCacheSchema::install()` and pass `initializeSchema: false` to `PdoCacheAdapter` in deployment-controlled environments.

`phpFiles` creates executable PHP files and is only appropriate for a trusted directory and trusted payloads. Never point SQLite at NFS, SMB, or another shared network filesystem.

## Tiering

```php
$cache = Cache::tiered([
    ['driver' => 'apcu', 'namespace' => 'app'],
    ['driver' => 'valkey', 'namespace' => 'app'],
]);
```

A bulk read asks L1 for the full batch, asks later tiers only for remaining keys, and promotes hits upward in batches. Writes and deletes are one batch per participating tier. The tiered facade intentionally does not expose atomic cache coordination because multiple tiers cannot form one linearizable authority.

## Immutable security and failure policy

Payload and runtime policy is provided at construction and never stored globally:

```php
use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\CacheLayer\Cache\CacheOptions;

function createCache(string $integrityKey): Cache
{
    return Cache::redis('app', options: new CacheOptions(
        integrityKey: $integrityKey,
        maxPayloadBytes: 8_388_608,
        compressionThreshold: 4096,
        compressionLevel: 6,
        allowClosures: false,
        allowObjects: false,
        failOpen: true,
    ));
}
```

Records use only the CacheLayer v2 markers `cl2:`, `cl2-gz:`, and `cl2-sig:`. Compression is threshold-based and retained only when smaller. HMAC verification, payload bounds, bounded decompression, and deserialization policy are isolated per cache instance. Corrupt payloads are safe misses.

Construction and configuration errors throw. Runtime backend failures default to fail-open: reads become misses, writes/deletes return `false`, and `backend_failure` is recorded. Set `failOpen: false` to propagate runtime failures. Pass deploy-varying values from the application's composition root; CacheLayer never reads process environment state.

## Node Cache

```php
use Infocyph\CacheLayer\Node\NodeCache;
use Infocyph\CacheLayer\Node\NodeCacheConfig;

$node = NodeCache::create(new NodeCacheConfig(
    namespace: 'app',
    sqliteFile: '/var/cache/my-app/cache.sqlite',
));
```

Node Cache combines an APCu L1 with a local SQLite L2, uses miss-only bulk L2 reads and bulk L1 promotion, and retains WAL, `synchronous=NORMAL`, bounded busy timeout, bounded pruning, checkpoint, and optimization maintenance.

## Cluster Cache

Cluster Cache adds durable invalidation around independent Node Caches. It keeps per-node cursors, replay, retention-gap recovery, consumer status, key/tag/namespace invalidation, bounded draining, PDO or Redis/Valkey Streams transports, and a transactional outbox.

```php
$runtime->invalidateKey('product.42');
$runtime->invalidateTags(['products', 'catalog']);
$runtime->clearNamespace();
$runtime->consume();
```

Failed local invalidation stops consumption without advancing the cursor; operators can repair the cause and retry. A poison event can only be skipped explicitly with `skipEventAfterClear()`, which clears the local namespace before advancing. Plain key invalidation cannot fence an in-flight resolver, so mutable read-through data that requires ordering should also use a tag generation. It does not replicate values and is not a distributed lock, session store, or counter system.

## Atomic counters and memoization

`AtomicCounters` uses an `AtomicCounterStoreInterface`; Redis/Valkey is the distributed implementation. Counters are never emulated with cache `get()` plus `set()`. Atomic counters are separate from `Cache::atomic()`: counters mutate numeric state, while the cache capability provides conditional claim/replace/consume primitives for encoded cache records.

The `memoize()`, `remember(object: ...)`, and `once()` helpers plus `MemoizeTrait` provide bounded process-local memoization. Their state survives requests in persistent workers until evicted or reset with `flush_memoizers()`; call that reset at request boundaries when cross-request reuse is not intended. They are independent of persistent backend caching.

## Metrics and benchmarks

Metrics distinguish calls from key volume: `get_batch`, `get_batch_keys`, hits/misses, set/delete batch counts, tag-generation fetches, promotions, lock outcomes, atomic claim/compare/consume outcomes, and backend failures. `exportMetrics()` returns a snapshot and can invoke an export hook.

PHPBench scenarios in `benchmarks/` cover single operations, 10/100/1000-key bulk operations, tagged/plain records, tier and Node promotion, codec security/compression, and remember paths. Backend-focused tests separately verify operation counts for native bulk calls. These are microbenchmarks, not production throughput claims.

## Development

```bash
composer ic:doctor
composer ic:process
composer ic:tests
```

Integration suites self-skip when their optional service or extension is unavailable.

## Security

Do not disclose suspected vulnerabilities in a public issue, discussion or pull request. Follow [SECURITY.md](SECURITY.md) and use [GitHub private vulnerability reporting](https://github.com/infocyph/CacheLayer/security/advisories/new).

CacheLayer is protected by [PHPForge](https://github.com/infocyph/PHPForge), which provides automated tests, static and taint analysis, dependency auditing, architecture checks and release-readiness gates. Automated controls do not replace responsible disclosure or manual review.

---

<div align="center">
  <sub><strong>Made with ❤️ for the PHP community</strong></sub><br />
  <sub><a href="LICENSE">MIT Licensed</a></sub><br />
  <a href="https://docs.infocyph.com/projects/CacheLayer/">Documentation</a> •
  <a href="SECURITY.md">Security</a> •
  <a href="CODE_OF_CONDUCT.md">Code of Conduct</a> •
  <a href="CONTRIBUTING.md">Contributing</a><br />
  <span title="Issue templates" aria-label="Issue templates">🗂️</span>
  <a href="https://github.com/infocyph/CacheLayer/issues/new?template=bug_report.yml">Bug</a> •
  <a href="https://github.com/infocyph/CacheLayer/issues/new?template=feature_request.yml">Feature</a> •
  <a href="https://github.com/infocyph/CacheLayer/issues/new?template=docs_improvement.yml">Documentation</a> •
  <a href="https://github.com/infocyph/CacheLayer/issues/new?template=question.yml">Question</a> •
  <a href="https://github.com/infocyph/CacheLayer/issues/new?template=ci_failure.yml">CI failure</a><br />
  <span title="Pull request templates" aria-label="Pull request templates">🔀</span>
  <a href="https://github.com/infocyph/CacheLayer/compare/main...HEAD?quick_pull=1&amp;template=PULL_REQUEST_TEMPLATE.md">General</a> •
  <a href="https://github.com/infocyph/CacheLayer/compare/main...HEAD?quick_pull=1&amp;template=bug_fix.md">Bug fix</a> •
  <a href="https://github.com/infocyph/CacheLayer/compare/main...HEAD?quick_pull=1&amp;template=feature.md">Feature</a> •
  <a href="https://github.com/infocyph/CacheLayer/compare/main...HEAD?quick_pull=1&amp;template=refactor.md">Refactor</a> •
  <a href="https://github.com/infocyph/CacheLayer/compare/main...HEAD?quick_pull=1&amp;template=performance.md">Performance</a> •
  <a href="https://github.com/infocyph/CacheLayer/compare/main...HEAD?quick_pull=1&amp;template=security_reliability.md">Security &amp; reliability</a> •
  <a href="https://github.com/infocyph/CacheLayer/compare/main...HEAD?quick_pull=1&amp;template=documentation.md">Documentation</a> •
  <a href="https://github.com/infocyph/CacheLayer/compare/main...HEAD?quick_pull=1&amp;template=maintenance.md">Maintenance</a>
</div>
