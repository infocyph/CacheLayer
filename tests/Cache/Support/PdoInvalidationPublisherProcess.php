<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cluster\Event\InvalidationEvent;
use Infocyph\CacheLayer\Cluster\Transport\Pdo\PdoInvalidationTransport;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

if (count($argv) !== 11) {
    throw new RuntimeException('Invalid concurrent invalidation publisher arguments.');
}

[
    ,
    $dsn,
    $user,
    $password,
    $cluster,
    $identifier,
    $stateFile,
    $resultFile,
    $commit,
    $holdMicros,
    $origin,
] = $argv;

$connection = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$transport = new PdoInvalidationTransport($connection, initializeSchema: false);
$connection->beginTransaction();
file_put_contents($stateFile, 'started');
$id = $transport->publishWithinTransaction(
    $connection,
    InvalidationEvent::key($cluster, 'application', $identifier, $origin),
);
file_put_contents($stateFile, 'acquired');

$holdMicros = (int) $holdMicros;
if ($holdMicros > 0) {
    usleep($holdMicros);
}
if ($commit === '1') {
    $connection->commit();
    file_put_contents($resultFile, $id);
}
