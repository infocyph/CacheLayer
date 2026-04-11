<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cache\Metrics;

final class InMemoryCacheMetricsCollector implements CacheMetricsCollectorInterface
{
    /**
     * @var array<string, array<string, int>>
     */
    private array $counters = [];

    public function export(): array
    {
        return $this->counters;
    }

    public function increment(string $adapterClass, string $metric): void
    {
        $this->counters[$adapterClass][$metric] = ($this->counters[$adapterClass][$metric] ?? 0) + 1;
    }
}
