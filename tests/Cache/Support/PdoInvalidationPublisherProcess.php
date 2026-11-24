<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cluster\Event\InvalidationEvent;
use Infocyph\CacheLayer\Cluster\Transport\Pdo\PdoInvalidationTransport;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

/** @throws RuntimeException */
function publisherArgument(mixed $value): string
{
    if (!is_string($value)) {
        throw new RuntimeException('Invalid invalidation publisher argument.');
    }

    return $value;
}

$arguments = $_SERVER['argv'] ?? [];
if (!is_array($arguments) || count($arguments) !== 11) {
    throw new RuntimeException('Invalid concurrent invalidation publisher arguments.');
}

$dsn = publisherArgument($arguments[1] ?? null);
$user = publisherArgument($arguments[2] ?? null);
$password = publisherArgument($arguments[3] ?? null);
$cluster = publisherArgument($arguments[4] ?? null);
$identifier = publisherArgument($arguments[5] ?? null);
$stateFile = publisherArgument($arguments[6] ?? null);
$resultFile = publisherArgument($arguments[7] ?? null);
$commit = publisherArgument($arguments[8] ?? null);
$holdMicros = publisherArgument($arguments[9] ?? null);
$origin = publisherArgument($arguments[10] ?? null);

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
