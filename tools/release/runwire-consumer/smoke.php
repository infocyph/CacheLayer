<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use Infocyph\CacheLayer\Integration\Runwire\RunwireIntegration;
use Infocyph\Runwire\RuntimeContext;

if (!class_exists(RuntimeContext::class) || !class_exists(RunwireIntegration::class)) {
    throw new RuntimeException('Runwire and CacheLayer integration classes must both be installed.');
}

$version = InstalledVersions::getPrettyVersion('infocyph/runwire');
$normalizedVersion = InstalledVersions::getVersion('infocyph/runwire');
if (!is_string($version) || !is_string($normalizedVersion)
    || version_compare($normalizedVersion, '4.0.1.0', '<')
    || version_compare($normalizedVersion, '5.0.0.0', '>=')) {
    throw new RuntimeException('The release integration gate requires Runwire 4.0.1 or newer within the 4.x series.');
}

require dirname(__DIR__, 3) . '/examples/runwire-invalidation-worker.php';

$extensions = array_values(array_filter(
    ['pcntl', 'posix', 'event', 'swoole', 'openswoole'],
    extension_loaded(...),
));

fwrite(STDOUT, sprintf(
    "CacheLayer Runwire consumer smoke passed with %s on PHP %s (%d-bit); native extensions: %s.\n",
    $version,
    PHP_VERSION,
    PHP_INT_SIZE * 8,
    $extensions === [] ? 'none' : implode(', ', $extensions),
));
