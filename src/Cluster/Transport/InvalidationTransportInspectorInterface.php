<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cluster\Transport;

interface InvalidationTransportInspectorInterface extends InvalidationTransportInterface
{
    public function countAfter(string $cluster, ?string $cursor): int;

    #[\Override]
    public function newestAvailableId(string $cluster): ?string;
}
