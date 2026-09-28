<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cache\AtomicCacheInterface;
use Infocyph\CacheLayer\Cache\Cache;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$rawSeeds = getenv('CACHELAYER_REDIS_CLUSTER_SEEDS');
if (!is_string($rawSeeds) || $rawSeeds === '') {
    throw new RuntimeException('CACHELAYER_REDIS_CLUSTER_SEEDS is required.');
}

$seeds = array_values(array_filter(array_map('trim', explode(',', $rawSeeds))));
if (count($seeds) < 3) {
    throw new RuntimeException('Real Redis Cluster verification requires at least three seeds.');
}

$cache = Cache::redisCluster('release-cluster', $seeds);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$values = [];
for ($index = 0; $index < 64; ++$index) {
    $values['key-' . $index] = 'value-' . $index;
}

$assert($cache->setMultiple($values, 60), 'Redis Cluster bulk write failed.');
$read = $cache->getMultiple(array_keys($values));
$assert($read === $values, 'Redis Cluster bulk read did not preserve values across slots.');

$atomic = $cache->atomic();
if (!$atomic instanceof AtomicCacheInterface) {
    throw new RuntimeException('Redis Cluster atomic capability is unavailable.');
}
$assert($atomic->setIfAbsent('claim', 'first', 60), 'Redis Cluster first atomic claim failed.');
$assert(!$atomic->setIfAbsent('claim', 'second', 60), 'Redis Cluster duplicate atomic claim succeeded.');
$assert($atomic->compareAndSet('claim', 'first', 'replaced', 60), 'Redis Cluster compare-and-set failed.');
$assert($atomic->getAndDelete('claim', 'missing') === 'replaced', 'Redis Cluster atomic consume failed.');
$assert($atomic->getAndDelete('claim', 'missing') === 'missing', 'Redis Cluster atomic consume was not one-time.');

$assert($cache->setTagged('tagged', 'v1', ['group'], 60), 'Redis Cluster tagged write failed.');
$assert($cache->invalidateTag('group'), 'Redis Cluster tag invalidation failed.');
$assert($cache->get('tagged') === null, 'Redis Cluster stale tagged value survived invalidation.');

$assert($cache->clear(), 'Redis Cluster namespace clear failed.');
$assert($cache->get('key-1') === null, 'Redis Cluster clear did not rotate namespace generation.');

fwrite(STDOUT, "CacheLayer real Redis Cluster smoke passed.\n");
