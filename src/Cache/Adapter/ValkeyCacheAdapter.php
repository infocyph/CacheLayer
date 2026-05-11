<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cache\Adapter;

final class ValkeyCacheAdapter extends RedisCacheAdapter
{
    public function __construct(
        string $namespace = 'default',
        string $dsn = 'valkey://127.0.0.1:6379',
        ?\Redis $client = null,
    ) {
        parent::__construct($namespace, $dsn, $client);
    }
}
