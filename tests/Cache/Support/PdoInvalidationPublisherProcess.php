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

/**
 * @param resource $stream
 * @throws RuntimeException
 */
function writePublisherState(mixed $stream, string $value): void
{
    if (!rewind($stream) || !ftruncate($stream, 0)
        || fwrite($stream, $value) !== strlen($value) || !fflush($stream)) {
        throw new RuntimeException('Unable to write publisher state.');
    }
}

/**
 * @return list<mixed>
 * @throws RuntimeException
 */
function publisherArguments(mixed $arguments): array
{
    if (!is_array($arguments) || !array_is_list($arguments) || count($arguments) !== 8) {
        throw new RuntimeException('Invalid concurrent invalidation publisher arguments.');
    }

    return $arguments;
}

if (PHP_SAPI !== 'cli') {
    throw new RuntimeException('Invalid concurrent invalidation publisher invocation.');
}
$input = stream_get_contents(STDIN);
if (!is_string($input)) {
    throw new RuntimeException('Unable to read publisher arguments.');
}
$arguments = publisherArguments(json_decode($input, true, 512, JSON_THROW_ON_ERROR));

$dsn = publisherArgument($arguments[0] ?? null);
$user = publisherArgument($arguments[1] ?? null);
$password = publisherArgument($arguments[2] ?? null);
$cluster = publisherArgument($arguments[3] ?? null);
$identifier = publisherArgument($arguments[4] ?? null);
$commit = publisherArgument($arguments[5] ?? null);
$hold = publisherArgument($arguments[6] ?? null);
$origin = publisherArgument($arguments[7] ?? null);
if (!in_array($hold, ['0', '150000'], true) || !in_array($commit, ['0', '1'], true)) {
    throw new RuntimeException('Invalid publisher control arguments.');
}

$state = fopen('php://fd/3', 'r+');
$result = fopen('php://fd/4', 'r+');
if ($state === false || $result === false) {
    throw new RuntimeException('Publisher requires parent-owned state and result descriptors.');
}

try {
    $connection = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $transport = new PdoInvalidationTransport($connection, initializeSchema: false);
    $connection->beginTransaction();
    writePublisherState($state, 'started');
    $id = $transport->publishWithinTransaction(
        $connection,
        InvalidationEvent::key($cluster, 'application', $identifier, $origin),
    );
    writePublisherState($state, 'acquired');

    if ($hold === '150000') {
        usleep(150_000);
    }
    if ($commit === '1') {
        $connection->commit();
        writePublisherState($result, $id);
    }
} finally {
    fclose($state);
    fclose($result);
}
