.. _adapters.redis:

=========================
Redis Adapter (`redis`)
=========================

Factory:

`Cache::redis(string $namespace = 'default', string $dsn = 'redis://127.0.0.1:6379', ?Redis $client = null)`

Requirements:

* `ext-redis` (phpredis)
* reachable Redis server

Highlights:

* distributed cache with namespace key prefixing
* `MGET` batch retrieval
* TTL via `SETEX` when expiration is set
* factory auto-configures `RedisLockProvider` for `remember()` when using this adapter

DSN notes:

* host/port parsed from DSN
* optional password and DB selection (`/db-index`) are supported
