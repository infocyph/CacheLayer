<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cache\Lock;

final readonly class LockHandle
{
    public function __construct(
        public string $key,
        public string $token,
        public mixed $resource = null,
    ) {}
}
