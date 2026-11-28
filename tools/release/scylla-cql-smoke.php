<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\CacheLayer\Cache\CacheOptions;
use Infocyph\CacheLayer\Support\OptionalCassandra;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

if (!OptionalCassandra::available()) {
    throw new RuntimeException('Real Scylla CQL verification requires ext-cassandra.');
}

$cache = Cache::scylla(
    'release-cql',
    keyspace: 'cachelayer',
    table: 'cachelayer_entries',
    bucketCount: 16,
    options: new CacheOptions(failOpen: false),
);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$assert($cache->set('plain', 'value', 60), 'Scylla CQL write failed.');
$assert($cache->get('plain') === 'value', 'Scylla CQL read failed.');

$batch = [];
for ($index = 0; $index < 48; ++$index) {
    $batch['batch-' . $index] = $index;
}
$assert($cache->setMultiple($batch, 60), 'Scylla CQL batch write failed.');
$read = $cache->getMultiple(array_keys($batch));
$assert($read === $batch, 'Scylla CQL batch read failed.');

$assert($cache->setTagged('tagged', 'v1', ['group'], 60), 'Scylla CQL tagged write failed.');
$assert($cache->invalidateTag('group'), 'Scylla CQL tag rotation failed.');
$assert($cache->get('tagged') === null, 'Scylla CQL stale tagged record survived invalidation.');

$assert($cache->delete('plain'), 'Scylla CQL delete failed.');
$assert($cache->get('plain') === null, 'Scylla CQL delete did not remove the value.');
$assert($cache->clear(), 'Scylla CQL namespace clear failed.');
$assert($cache->get('batch-1') === null, 'Scylla CQL clear did not remove namespace values.');

$coordination = Cache::scylla(
    'release-cql-atomic',
    bucketCount: 16,
    options: new CacheOptions(failOpen: false),
    atomicMode: true,
);
$peer = Cache::scylla(
    'release-cql-atomic',
    bucketCount: 16,
    options: new CacheOptions(failOpen: false),
    atomicMode: true,
);
$atomic = $coordination->atomic() ?? throw new RuntimeException('Scylla LWT capability unavailable.');
$other = $peer->atomic() ?? throw new RuntimeException('Scylla peer LWT capability unavailable.');
$assert($coordination->clear(), 'Scylla LWT initial clear failed.');
$assert($atomic->setIfAbsent('claim', "a\\0\\xff", 60), 'Scylla LWT claim failed.');
$assert(!$other->setIfAbsent('claim', 'other', 60), 'Scylla LWT allowed a second winner.');
$assert(!$other->compareAndSet('claim', 'wrong', 'bad'), 'Scylla LWT accepted incorrect comparison.');
$assert($other->compareAndSet('claim', "a\\0\\xff", 'updated'), 'Scylla LWT CAS failed.');
$assert($coordination->get('claim') === 'updated', 'Scylla SERIAL read did not observe LWT write.');
$assert($coordination->set('claim', 'ordinary'), 'Scylla LWT-mode ordinary write failed.');
$assert($other->getAndDelete('claim') === 'ordinary', 'Scylla LWT consume failed.');
$assert($atomic->getAndDelete('claim', 'missing') === 'missing', 'Scylla LWT consumed twice.');
$assert($atomic->setIfAbsent('ttl', 'old', 1), 'Scylla LWT TTL write failed.');
usleep(2_000_000);
$assert($other->setIfAbsent('ttl', 'fresh', 60), 'Scylla LWT expired entry could not be reclaimed.');
$assert($peer->get('ttl') === 'fresh', 'Scylla LWT reclaimed value is missing.');
$assert($coordination->setMultiple(['batch-a' => 1, 'batch-b' => 2]), 'Scylla LWT batch write failed.');
$assert($peer->getMultiple(['batch-a', 'batch-b']) === ['batch-a' => 1, 'batch-b' => 2], 'Scylla LWT batch read failed.');
$assert($peer->deleteMultiple(['batch-a', 'batch-b']), 'Scylla LWT batch delete failed.');
$assert($coordination->clear(), 'Scylla LWT clear failed.');
$assert(!$peer->has('ttl'), 'Scylla LWT clear left a value.');

fwrite(STDOUT, "CacheLayer real Scylla CQL and LWT smoke passed.\n");
