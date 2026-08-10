<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cache\Item;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use Infocyph\CacheLayer\Cache\Adapter\InternalCachePoolInterface;
use Psr\Cache\CacheItemInterface;

final class CacheItem implements CacheItemInterface
{
    /**
     * @param array<string, int> $tags
     */
    public function __construct(
        private readonly InternalCachePoolInterface $pool,
        private readonly string $key,
        private mixed $value = null,
        private bool $hit = false,
        private ?DateTimeInterface $expiration = null,
        private array $tags = [],
    ) {}

    public function belongsTo(InternalCachePoolInterface $pool): bool
    {
        return $this->pool === $pool;
    }

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

    public function expiresAt(?DateTimeInterface $expiration): static
    {
        $this->expiration = $expiration;

        return $this;
    }

    public function get(): mixed
    {
        return $this->value;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    /** @return array<string, int> */
    public function getTagVersions(): array
    {
        return $this->tags;
    }

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

    public function set(mixed $value): static
    {
        $this->value = $value;
        $this->hit = true;

        return $this;
    }

    /**
     * @param array<string, int> $tags
     */
    public function setTagVersions(array $tags): static
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
