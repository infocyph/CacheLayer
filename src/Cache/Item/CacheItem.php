<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cache\Item;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use Infocyph\CacheLayer\Cache\Adapter\InternalCachePoolInterface;
use Infocyph\CacheLayer\Cache\CacheInput;
use Psr\Cache\CacheItemInterface;

final class CacheItem implements CacheItemInterface
{
    /**
     * @param array<int|string, string> $tags
     * @throws \Infocyph\CacheLayer\Exceptions\CacheInvalidArgumentException
     */
    public function __construct(
        private readonly InternalCachePoolInterface $pool,
        private readonly string $key,
        private mixed $value = null,
        private readonly bool $hit = false,
        private ?DateTimeInterface $expiration = null,
        private array $tags = [],
    ) {
        CacheInput::key($key);
    }

    public function belongsTo(InternalCachePoolInterface $pool): bool
    {
        return $this->pool === $pool;
    }

    #[\Override]
    public function expiresAfter(int|DateInterval|null $time): static
    {
        $now = new DateTimeImmutable();
        $this->expiration = match (true) {
            is_int($time) => $now->modify(sprintf('%+d seconds', $time)),
            $time instanceof DateInterval => $now->add($time),
            default => null,
        };

        return $this;
    }

    #[\Override]
    public function expiresAt(?DateTimeInterface $expiration): static
    {
        $this->expiration = $expiration;

        return $this;
    }

    #[\Override]
    public function get(): mixed
    {
        return $this->value;
    }

    #[\Override]
    public function getKey(): string
    {
        return $this->key;
    }

    /** @return array<int|string, string> */
    public function getTagGenerations(): array
    {
        return $this->tags;
    }

    #[\Override]
    public function isHit(): bool
    {
        return $this->hit
            && ($this->expiration === null || $this->expiration->getTimestamp() > time());
    }

    public function save(): static
    {
        $this->pool->internalPersist($this);

        return $this;
    }

    public function saveDeferred(): static
    {
        $this->pool->internalQueue($this);

        return $this;
    }

    #[\Override]
    public function set(mixed $value): static
    {
        $this->value = $value;

        return $this;
    }

    /**
     * @param array<int|string, string> $tags
     */
    public function setTagGenerations(array $tags): static
    {
        $this->tags = $tags;

        return $this;
    }

    public function ttlSeconds(): ?int
    {
        return $this->expiration === null
            ? null
            : $this->expiration->getTimestamp() - time();
    }
}
