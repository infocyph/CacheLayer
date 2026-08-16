<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cache;

use Infocyph\CacheLayer\Exceptions\CacheInvalidArgumentException;

final readonly class CacheOptions
{
    public function __construct(
        public ?string $integrityKey = null,
        public ?int $maxPayloadBytes = 8_388_608,
        public ?int $compressionThreshold = null,
        public int $compressionLevel = 6,
        public bool $allowClosures = true,
        public bool $allowObjects = true,
        public bool $failOpen = true,
    ) {
        if ($integrityKey === '') {
            throw new CacheInvalidArgumentException('The payload integrity key must be null or non-empty.');
        }
        if ($maxPayloadBytes !== null && $maxPayloadBytes < 1) {
            throw new CacheInvalidArgumentException('The maximum payload size must be positive or null.');
        }
        if ($compressionThreshold !== null && $compressionThreshold < 1) {
            throw new CacheInvalidArgumentException('The compression threshold must be positive or null.');
        }
        if ($compressionLevel < 1 || $compressionLevel > 9) {
            throw new CacheInvalidArgumentException('The compression level must be between 1 and 9.');
        }
    }

}
