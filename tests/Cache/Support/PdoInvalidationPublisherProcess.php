<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cluster\Event\InvalidationEvent;
use Infocyph\CacheLayer\Cluster\Transport\Pdo\PdoInvalidationTransport;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

if (count($argv) !== 11 || !array_is_list($argv) || !array_all($argv, is_string(...))) {
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

if (!is_string($dsn) || !is_string($cluster) || !is_string($identifier)
    || !is_string($stateFile) || !is_string($resultFile) || !is_string($origin)) {
    throw new RuntimeException('Missing required concurrent invalidation publisher arguments.');
}

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
