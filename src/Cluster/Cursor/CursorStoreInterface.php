<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cluster\Cursor;

interface CursorStoreInterface
{
    public function advance(string $eventId): void;

    public function current(): ?string;

    public function reset(?string $eventId): void;

    public function updatedAt(): ?int;
}
