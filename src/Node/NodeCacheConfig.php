<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Node;

use Infocyph\CacheLayer\Cache\CacheInput;
use Infocyph\CacheLayer\Cache\Lock\LockProviderInterface;
use Infocyph\CacheLayer\Exceptions\CacheInvalidArgumentException;
use Infocyph\CacheLayer\Node\Exception\NodeCacheConfigurationException;

final readonly class NodeCacheConfig
{
    public string $namespace;

    public function __construct(
        public string $sqliteFile,
        string $namespace = 'default',
        public ?string $lockDirectory = null,
        public int $busyTimeoutMs = 1_000,
        public bool $apcuEnabled = true,
        public bool $failOpen = true,
        public ?LockProviderInterface $lockProvider = null,
    ) {
        if ($sqliteFile === '' || str_contains($sqliteFile, "\0")) {
            throw new NodeCacheConfigurationException('The SQLite cache file path is invalid.');
        }

        if ($lockDirectory !== null && ($lockDirectory === '' || str_contains($lockDirectory, "\0"))) {
            throw new NodeCacheConfigurationException('The lock directory path is invalid.');
        }

        if ($busyTimeoutMs < 0) {
            throw new NodeCacheConfigurationException('The SQLite busy timeout cannot be negative.');
        }

        try {
            $this->namespace = CacheInput::namespace($namespace);
        } catch (CacheInvalidArgumentException $failure) {
            throw new NodeCacheConfigurationException($failure->getMessage(), 0, $failure);
        }
    }
}
