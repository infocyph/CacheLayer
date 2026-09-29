<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cluster\Recovery;

use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\CacheLayer\Cluster\Cursor\CursorStoreInterface;
use Infocyph\CacheLayer\Cluster\Exception\ClusterCacheException;
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
        if ($this->cursorStore->requiresRecovery()) {
            $this->clearLocalCache();
            $this->cursorStore->reset(null);

            return true;
        }

        $cursor = $this->cursorStore->current();
        if ($cursor === null) {
            return false;
        }

        $oldest = $this->transport->oldestAvailableId($this->cluster);
        if ($oldest === null) {
            $this->clearLocalCache();
            $this->cursorStore->reset(null);

            return true;
        }

        if ($this->transport->isCursorBefore($cursor, $oldest)) {
            $this->clearLocalCache();
            $this->cursorStore->reset($oldest);

            return true;
        }

        $newest = $this->transport->newestAvailableId($this->cluster);
        if ($newest !== null && $this->transport->isCursorBefore($newest, $cursor)) {
            $this->clearLocalCache();
            $this->cursorStore->reset(null);

            return true;
        }

        return false;
    }

    private function clearLocalCache(): void
    {
        if (!$this->cache->clear()) {
            throw new ClusterCacheException('Unable to clear the local cache during cluster recovery.');
        }
    }
}
