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
│   ├── versioned tags
│   ├── bounded stampede protection
│   └── tiering
├── Node Cache
│   └── APCu L1 → SQLite L2
├── Cluster Cache
│   └── durable invalidation between Node Caches
├── Atomic Counters
└── Process-local Memoization
```

The ordinary cache is disposable storage. Cluster Cache distributes invalidations, not values. Atomic counters remain outside the cache contract because they require stronger semantics. Memoization stays process-local.

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

`Cache` implements PSR-6, PSR-16, and `ArrayAccess`. It intentionally does not implement `Countable`, magic property access, runtime namespace mutation, or compatibility aliases. Keys and tags must be 1–64 characters and match `[A-Za-z0-9_.-]+`; invalid bulk input is rejected before storage is changed.

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

## Tags and expiration

```php
$cache->setTagged('article.7', $article, ['articles', 'author.12'], 600);
$cache->invalidateTags(['articles', 'author.12']);
```

Each record embeds its complete tag-version snapshot. Tag versions begin at zero, invalidation increments them atomically, and reads fetch all required versions in a batch. A mismatch makes the complete record stale; there are no per-entry reverse tag indexes or partially tagged writes.

Zero and negative PSR-16 TTLs delete the key. Missing tag metadata means version zero.

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

Redis Cluster uses 128 stable bucket hash tags. Memcached and Redis Cluster clear a namespace by advancing epochs, so they do not scan, flush other namespaces, or maintain a permanent key membership index.

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

Data and internal metadata use physically separate key spaces. SQL-like stores can install schema explicitly with `PdoCacheSchema::install()` and pass `initializeSchema: false` to `PdoCacheAdapter` in deployment-controlled environments.

`phpFiles` creates executable PHP files and is only appropriate for a trusted directory and trusted payloads. Never point SQLite at NFS, SMB, or another shared network filesystem.

## Tiering

```php
$cache = Cache::tiered([
    ['driver' => 'apcu', 'namespace' => 'app'],
    ['driver' => 'valkey', 'namespace' => 'app'],
]);
```

A bulk read asks L1 for the full batch, asks later tiers only for remaining keys, and promotes hits upward in batches. Writes and deletes are one batch per participating tier.

## Immutable security and failure policy

Payload and runtime policy is provided at construction and never stored globally:

```php
use Infocyph\CacheLayer\Cache\CacheOptions;

$options = new CacheOptions(
    integrityKey: $_ENV['CACHE_INTEGRITY_KEY'],
    maxPayloadBytes: 8_388_608,
    compressionThreshold: 4096,
    compressionLevel: 6,
    allowClosures: false,
    allowObjects: false,
    failOpen: true,
);

$cache = Cache::redis('app', options: $options);
```

Records use only the CacheLayer v2 markers `cl2:`, `cl2-gz:`, and `cl2-sig:`. Compression is threshold-based and retained only when smaller. HMAC verification, payload bounds, bounded decompression, and deserialization policy are isolated per cache instance. Corrupt payloads are safe misses.

Construction and configuration errors throw. Runtime backend failures default to fail-open: reads become misses, writes/deletes return `false`, and `backend_failure` is recorded. Set `failOpen: false` to propagate runtime failures. `CacheOptions::fromEnvironment()` explicitly reads `CACHELAYER_PAYLOAD_INTEGRITY_KEY` and `CACHELAYER_MAX_PAYLOAD_BYTES`; environment state is never read implicitly.

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
$runtime->invalidateNamespace();
$runtime->consume();
```

It does not replicate values and is not a distributed lock, session store, or counter system.

## Atomic counters and memoization

`AtomicCounters` uses an `AtomicCounterStoreInterface`; Redis/Valkey is the distributed implementation. Counters are never emulated with cache `get()` plus `set()`.

The `memoize()`, `remember(object: ...)`, and `once()` helpers plus `MemoizeTrait` provide bounded process-local memoization. They are independent of persistent backend caching.

## Metrics and benchmarks

Metrics distinguish calls from key volume: `get_batch`, `get_batch_keys`, hits/misses, set/delete batch counts, tag-version fetches, promotions, lock outcomes, and backend failures. `exportMetrics()` returns a snapshot and can invoke an export hook.

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
