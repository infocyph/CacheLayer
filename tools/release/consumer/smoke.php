<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cache\Cache;

$cache = Cache::memory('consumer');
if (!$cache->set('ready', ['version' => 4], 30)) {
    throw new RuntimeException('Consumer cache write failed.');
}
if ($cache->get('ready') !== ['version' => 4]) {
    throw new RuntimeException('Consumer cache read failed.');
}

fwrite(STDOUT, "CacheLayer production consumer smoke passed.\n");
