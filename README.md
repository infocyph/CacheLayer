# CacheLayer

CacheLayer is a standalone cache toolkit for modern PHP applications.

It provides:

- PSR-6 and PSR-16 compatible `Cache` facade
- Adapters for `APCu`, `File`, `Memcached`, `Redis`, `Redis Cluster`, `PDO (default SQLite; also MySQL/MariaDB/PostgreSQL/etc.)`
- In-process adapters: `memory` (array), `weakMap`, `nullStore`, `chain`
- Filesystem/Opcode adapter: `phpFiles`
- Shared-memory adapter: `sharedMemory` (sysvshm)
- Cloud adapters: `mongodb`, `dynamoDb`, `s3` (SDK/client injected or auto-created when SDK is installed)
- Tag-version invalidation (`setTagged()`, `invalidateTag()`, `invalidateTags()`) without full key scans
- Stampede-safe `remember()` with pluggable lock providers (file/redis/memcached)
- Per-adapter metrics counters with export hooks
- Optional payload compression via `configurePayloadCompression()`
- Serializer helpers for closures/resources used by cache payloads
- Memoization primitives: `Memoizer`, `MemoizeTrait`, and helpers `memoize()`, `remember()`, `once()`

Quick usage:

```php
use Infocyph\CacheLayer\Cache\Cache;

$cache = Cache::memory('app');
$cache->setTagged('user:1', ['name' => 'A'], ['users'], 300);

$cache->invalidateTag('users'); // all entries tagged with "users" become stale

$value = $cache->remember('expensive', fn () => compute(), 60);
$metrics = $cache->exportMetrics();
```

Factory overview:

```php
Cache::apcu('ns');
Cache::file('ns', __DIR__ . '/storage/cache');
Cache::phpFiles('ns', __DIR__ . '/storage/cache');
Cache::memcache('ns');
Cache::redis('ns');
Cache::redisCluster('ns', ['127.0.0.1:6379']);
Cache::pdo('ns'); // defaults to sqlite file in sys temp dir
Cache::sqlite('ns');
Cache::pdo('ns', 'mysql:host=127.0.0.1;port=3306;dbname=app', 'user', 'pass');
Cache::memory('ns');
Cache::weakMap('ns');
Cache::sharedMemory('ns');
Cache::nullStore();
Cache::chain([
    new Infocyph\CacheLayer\Cache\Adapter\ArrayCacheAdapter('l1'),
    new Infocyph\CacheLayer\Cache\Adapter\RedisCacheAdapter('l2'),
]);
```

Namespace:

- `Infocyph\CacheLayer\Cache\...`
- `Infocyph\CacheLayer\Cache\Lock\...`
- `Infocyph\CacheLayer\Cache\Metrics\...`
- `Infocyph\CacheLayer\Serializer\...`
- `Infocyph\CacheLayer\Exceptions\...`
