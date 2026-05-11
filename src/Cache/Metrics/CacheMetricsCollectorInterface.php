<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cache\Metrics;

interface CacheMetricsCollectorInterface
{
    /**
     * @return array<string, array<string, int>>
     */
    public function export(): array;

    public function increment(string $adapterClass, string $metric): void;
}
