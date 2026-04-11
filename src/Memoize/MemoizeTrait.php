<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Memoize;

trait MemoizeTrait
{
    /** @var array<string, mixed> */
    private array $__memo = [];

    protected function memoize(string $key, callable $producer): mixed
    {
        if (!array_key_exists($key, $this->__memo)) {
            $this->__memo[$key] = $producer();
        }

        return $this->__memo[$key];
    }

    protected function memoizeClear(?string $key = null): void
    {
        if ($key === null) {
            $this->__memo = [];
            return;
        }

        unset($this->__memo[$key]);
    }
}
