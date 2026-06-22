<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cache;

use Infocyph\CacheLayer\Exceptions\CacheInvalidArgumentException;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\InvalidArgumentException as Psr6InvalidArgumentException;

trait CacheReadRememberTrait
{
    public function get(string $key, mixed $default = null): mixed
    {
        $this->validateKey($key);

        if (is_callable($default)) {
            return $this->remember($key, $default);
        }

        try {
            $item = $this->adapter->getItem($key);
        } catch (Psr6InvalidArgumentException $e) {
            throw new CacheInvalidArgumentException($e->getMessage(), 0, $e);
        }

        if (!$item->isHit()) {
            $this->metric('miss');

            return $default;
        }

        if (!$this->isTagMetaValid($key)) {
            $this->purgeKeyAndTagMeta($key);
            $this->metric('miss');

            return $default;
        }

        $this->metric('hit');

        return $item->get();
    }

    public function getItem(string $key): CacheItemInterface
    {
        $this->validateKey($key);
        $item = $this->adapter->getItem($key);
        if (!$item->isHit()) {
            return $item;
        }

        if (!$this->isTagMetaValid($key)) {
            $this->purgeKeyAndTagMeta($key);

            return $this->adapter->getItem($key);
        }

        return $item;
    }

    /**
     * @param array $keys The keys argument.
     * @phpstan-param string[] $keys
     * @phpstan-return iterable<CacheItemInterface>
     */
    public function getItems(array $keys = []): iterable
    {
        if ($keys === []) {
            return new \EmptyIterator();
        }

        foreach ($keys as $key) {
            $this->validateKey((string) $key);
        }

        $fetched = method_exists($this->adapter, 'multiFetch')
            ? $this->adapter->multiFetch($keys)
            : iterator_to_array($this->adapter->getItems($keys), true);

        /** @var array<string, CacheItemInterface> $out */
        $out = [];
        foreach ($keys as $key) {
            $k = (string) $key;
            $fetchedItem = is_array($fetched) ? ($fetched[$k] ?? null) : null;
            $item = $fetchedItem instanceof CacheItemInterface ? $fetchedItem : $this->adapter->getItem($k);

            if (!$item->isHit()) {
                $this->metric('miss');
                $out[$k] = $item;

                continue;
            }

            if (!$this->isTagMetaValid($k)) {
                $this->purgeKeyAndTagMeta($k);
                $this->metric('miss');
                $out[$k] = $this->adapter->getItem($k);

                continue;
            }

            $this->metric('hit');
            $out[$k] = $item;
        }

        return $out;
    }

    public function hasItem(string $key): bool
    {
        $this->validateKey($key);
        $item = $this->adapter->getItem($key);
        if (!$item->isHit()) {
            $this->metric('miss');

            return false;
        }

        if (!$this->isTagMetaValid($key)) {
            $this->purgeKeyAndTagMeta($key);
            $this->metric('miss');

            return false;
        }

        $this->metric('hit');

        return true;
    }

    /**
     * @throws Psr6InvalidArgumentException
     * @param string $key The key argument.
     * @param callable $resolver The resolver argument.
     * @param mixed $ttl The ttl argument.
     * @param array $tags The tags argument.
     * @phpstan-param array<int, string> $tags
     */
    public function remember(
        string $key,
        callable $resolver,
        mixed $ttl = null,
        array $tags = [],
    ): mixed {
        $this->validateKey($key);
        $normalizedTtl = $this->normalizeTtl($ttl);
        $normalizedTags = $this->normalizeTagList($tags);

        try {
            $item = $this->getItem($key);
        } catch (Psr6InvalidArgumentException $e) {
            throw new CacheInvalidArgumentException($e->getMessage(), 0, $e);
        }

        if ($item->isHit()) {
            $this->metric('remember_hit');

            return $item->get();
        }

        $lockHandle = $this->lockProvider->acquire($this->stampedeLockKey($key), self::STAMPEDE_LOCK_WAIT_SECONDS);

        try {
            $lockedItem = $this->getItem($key);
            if ($lockedItem->isHit()) {
                $this->metric('remember_hit');

                return $lockedItem->get();
            }

            if ($normalizedTtl !== null) {
                $lockedItem->expiresAfter($normalizedTtl);
            }

            $computed = $resolver($lockedItem);
            $lockedItem->set($computed);
            $this->applyJitteredTtl($lockedItem);
            $this->save($lockedItem);

            if ($normalizedTags !== [] && !$this->writeTagMeta($key, $normalizedTags, $normalizedTtl)) {
                throw new CacheInvalidArgumentException("Unable to store tag metadata for key '$key'");
            }

            $this->metric('remember_miss');

            return $computed;
        } finally {
            $this->lockProvider->release($lockHandle);
        }
    }
}
