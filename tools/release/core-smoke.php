<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\CacheLayer\Cache\CacheOptions;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$memory = Cache::memory('release-core');
$assert($memory->set('plain', ['ok' => true], 30), 'Memory cache write failed.');
$assert($memory->get('plain') === ['ok' => true], 'Memory cache read failed.');

$pending = $memory->getItem('deferred')->set('queued');
$assert($memory->saveDeferred($pending), 'Deferred queue failed.');
$assert($memory->get('deferred') === 'queued', 'Deferred value was not visible before commit.');
$assert($memory->delete('deferred'), 'Deferred delete failed.');
$assert($memory->commit(), 'Deferred commit failed.');
$assert($memory->get('deferred') === null, 'Deleted deferred value was resurrected.');

$assert($memory->setMultiple(['0' => 'zero', '01' => 'leading', '-1' => 'negative']), 'Numeric-string batch write failed.');
$values = $memory->getMultiple(['0', '01', '-1']);
$assert($values[0] === 'zero', 'Numeric key 0 did not round-trip.');
$assert($values['01'] === 'leading', 'Numeric key 01 did not round-trip.');
$assert($values[-1] === 'negative', 'Numeric key -1 did not round-trip.');

$options = new CacheOptions(
    integrityKey: str_repeat('k', 32),
    allowClosures: false,
    allowObjects: false,
);
$signed = Cache::memory('release-signed', $options);
$assert($signed->set('signed', 'value'), 'Signed cache write failed.');
$assert($signed->get('signed') === 'value', 'Signed cache read failed.');

$base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cachelayer-release-' . bin2hex(random_bytes(6));
$file = Cache::file('release-file', $base . DIRECTORY_SEPARATOR . 'file');
$phpFiles = Cache::phpFiles('release-php-files', $base . DIRECTORY_SEPARATOR . 'php-files');

$assert($file->set('disk', 'file-value', 30), 'File cache write failed.');
$assert($file->get('disk') === 'file-value', 'File cache read failed.');
$assert($phpFiles->set('disk', 'php-file-value', 30), 'PHP-files cache write failed.');
$assert($phpFiles->get('disk') === 'php-file-value', 'PHP-files cache read failed.');
$assert($file->clear(), 'File cache clear failed.');
$assert($phpFiles->clear(), 'PHP-files cache clear failed.');

fwrite(STDOUT, sprintf("CacheLayer core release smoke passed on PHP %s.\n", PHP_VERSION));
