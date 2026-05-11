# CacheLayer

[![Security & Standards](https://github.com/infocyph/CacheLayer/actions/workflows/security-standards.yml/badge.svg)](https://github.com/infocyph/CacheLayer/actions/workflows/security-standards.yml)
![Packagist Downloads](https://img.shields.io/packagist/dt/infocyph/CacheLayer?color=green\&link=https%3A%2F%2Fpackagist.org%2Fpackages%2Finfocyph%2FCacheLayer)
[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](https://opensource.org/licenses/MIT)
![Packagist Version](https://img.shields.io/packagist/v/infocyph/CacheLayer)
![Packagist PHP Version](https://img.shields.io/packagist/dependency-v/infocyph/CacheLayer/php)
![GitHub Code Size](https://img.shields.io/github/languages/code-size/infocyph/CacheLayer)
[![Documentation](https://img.shields.io/badge/Documentation-CacheLayer-blue?logo=readthedocs&logoColor=white)](https://docs.infocyph.com/projects/CacheLayer/)

CacheLayer is a standalone cache toolkit for modern PHP applications.
It provides a unified API over PSR-6 and PSR-16 with local, distributed,
and cloud adapters.

## Project Background

CacheLayer was separated from the existing Intermix project to improve package
visibility, maintenance focus, and faster feature enrichment for caching.

## Features

- Unified `Cache` facade implementing PSR-6, PSR-16, `ArrayAccess`, and `Countable`
- Adapter support for APCu, File, PHP Files, Memcached, Redis, Valkey, Redis Cluster, PDO (SQLite default), Shared Memory, MongoDB, and ScyllaDB
- Tiered cache composition via `Cache::tiered()` (L1/L2/... descriptors or pool instances)
- Tagged invalidation with versioned tags: `setTagged()`, `invalidateTag()`, `invalidateTags()`
- Stampede-safe `remember()` with pluggable lock providers
- Per-adapter metrics counters and export hooks
- Payload compression controls
- Value serializer helpers for closures/resources
- Memoization helpers: `memoize()`, `remember()`, `once()`

## Requirements

- PHP 8.3+
- Composer

Optional extensions/packages depend on adapter choice:

- `ext-apcu`
- `ext-redis`
- `ext-memcached`
- `ext-pdo` + driver (`pdo_sqlite`, `pdo_pgsql`, `pdo_mysql`, ...)
- `ext-sysvshm`
- `mongodb/mongodb`
- `ext-cassandra`

## Installation

```bash
composer require infocyph/cachelayer
```

## Usage

```php
use Infocyph\CacheLayer\Cache\Cache;

$cache = Cache::pdo('app'); // defaults to sqlite file under sys temp cachelayer/pdo

$cache->setTagged('user:1', ['name' => 'Ada'], ['users'], 300);

$user = $cache->remember('user:1', function ($item) {
    $item->expiresAfter(300);
    return ['name' => 'Ada'];
}, tags: ['users']);

$cache->invalidateTag('users');

$metrics = $cache->exportMetrics();
```

## Tiered Flow (L1 -> L2 -> DB)

```php
use Infocyph\CacheLayer\Cache\Cache;

$cache = Cache::tiered([
    ['driver' => 'apcu', 'namespace' => 'app'], // L1
    ['driver' => 'valkey', 'namespace' => 'app', 'dsn' => 'valkey://127.0.0.1:6379'], // L2
], writeToL1: false); // optional L1 write-through

$value = $cache->remember('user:42', function ($item) use ($pdo) {
    $item->expiresAfter(300);

    $stmt = $pdo->prepare('SELECT payload FROM users_cache_source WHERE id = ?');
    $stmt->execute([42]);

    return $stmt->fetchColumn();
});
```

Request flow:
- check APCu (L1)
- check Redis/Valkey (L2)
- query DB on miss
- write L2
- optionally write L1 (controlled by `writeToL1`)

## Security Hardening

CacheLayer includes optional payload/serialization hardening controls:

```php
$cache
    ->configurePayloadSecurity(
        integrityKey: 'replace-with-strong-secret',
        maxPayloadBytes: 8_388_608,
    )
    ->configureSerializationSecurity(
        allowClosurePayloads: false,
        allowObjectPayloads: false,
    );
```

You can also set:

- `CACHELAYER_PAYLOAD_INTEGRITY_KEY`
- `CACHELAYER_MAX_PAYLOAD_BYTES`

See `SECURITY.md` for deployment guidance and threat model notes.

## Documentation

https://docs.infocyph.com/projects/CacheLayer

## Testing

```bash
composer test:code
```

Or run the full test pipeline:

```bash
composer test:all
```

## Contributing

Contributions are welcome.

- Open an issue for bug reports or feature discussions
- Open a pull request with focused changes and tests
- Keep coding style and static checks passing before submitting

## License

MIT License. See [LICENSE](LICENSE).
