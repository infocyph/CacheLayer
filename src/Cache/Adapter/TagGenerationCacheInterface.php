<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cache\Adapter;

/** @internal */
interface TagGenerationCacheInterface
{
    /**
     * @param list<string> $tags
     * @return array<string, string>
     */
    public function readTagGenerations(array $tags): array;

    /** @param array<string, string> $generations */
    public function storeTagGenerations(array $generations): bool;
}
