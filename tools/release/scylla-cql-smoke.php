<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\CacheLayer\Support\OptionalCassandra;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

if (!OptionalCassandra::available()) {
    throw new RuntimeException('Real Scylla CQL verification requires ext-cassandra.');
}

$cache = Cache::scylla('release-cql', keyspace: 'cachelayer', table: 'cachelayer_entries', bucketCount: 16);
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

fwrite(STDOUT, "CacheLayer real Scylla CQL smoke passed.\n");
