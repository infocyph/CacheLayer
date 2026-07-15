<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Counter;

final readonly class AtomicCounterValue
{
    public function __construct(
        public int $value,
        public bool $initialized,
    ) {}
}
