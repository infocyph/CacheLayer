# CacheLayer

CacheLayer is a standalone cache toolkit for modern PHP applications.
It provides a unified API over PSR-6 and PSR-16 with local, distributed,
and cloud adapters.

## Features

- Unified `Cache` facade implementing PSR-6, PSR-16, `ArrayAccess`, and `Countable`
- Adapter support for APCu, File, PHP Files, Memcached, Redis, Redis Cluster, PDO (SQLite default), Shared Memory, MongoDB, DynamoDB, and S3
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
- `aws/aws-sdk-php`

## Installation

```bash
composer require infocyph/cachelayer
```

## Usage

```php
use Infocyph\CacheLayer\Cache\Cache;

$cache = Cache::pdo('app'); // defaults to sqlite file in sys temp dir

$cache->setTagged('user:1', ['name' => 'Ada'], ['users'], 300);

$user = $cache->remember('user:1', function ($item) {
    $item->expiresAfter(300);
    return ['name' => 'Ada'];
}, tags: ['users']);

$cache->invalidateTag('users');

$metrics = $cache->exportMetrics();
```

## Documentation

- User docs are in `docs/`
- Build docs locally with Sphinx (if installed):

```bash
make -C docs html
```

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
