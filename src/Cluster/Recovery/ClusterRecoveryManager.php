<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cluster\Recovery;

use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\CacheLayer\Cluster\Cursor\CursorStoreInterface;
use Infocyph\CacheLayer\Cluster\Transport\InvalidationTransportInterface;

final readonly class ClusterRecoveryManager
{
    public function __construct(
        private Cache $cache,
        private CursorStoreInterface $cursorStore,
        private InvalidationTransportInterface $transport,
        private string $cluster,
    ) {}

    public function recoverIfRequired(): bool
    {
        $cursor = $this->cursorStore->current();
        $oldest = $this->transport->oldestAvailableId($this->cluster);
        if ($cursor === null || $oldest === null || !$this->transport->isCursorBefore($cursor, $oldest)) {
            return false;
        }

        $this->cache->clear();
        $this->cursorStore->reset($oldest);

        return true;
    }
}
