.. _adapters.redis_cluster:

========================================
Redis Cluster Adapter (`redisCluster`)
========================================

Factory:

`Cache::redisCluster(string $namespace = 'default', array $seeds = ['127.0.0.1:6379'], float $timeout = 1.0, float $readTimeout = 1.0, bool $persistent = false, ?object $client = null)`

Requirements:

* RedisCluster support via `ext-redis`, or
* injected client exposing expected methods (`get`, `set`, `setex`, `del`, `exists`, `sAdd`, `sRem`, `sCard`, `sMembers`)

Highlights:

* cluster-aware storage
* tracks namespace key membership through an index set (`<ns>:__keys`) for clear/count operations

Useful when using Redis Cluster topology.
