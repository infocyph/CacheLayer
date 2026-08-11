<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cache;

/** @internal */
final readonly class CacheRecord
{
    /**
     * @param array<string, string> $tags
     */
    public function __construct(
        public mixed $value,
        public ?int $expiresAt = null,
        public array $tags = [],
        public ?string $namespaceGeneration = null,
    ) {}
}
