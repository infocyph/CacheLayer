<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Tests\Cache\Support;

use RuntimeException;

final class RedisClusterDouble
{
    /** @var array<string, array{value:string,expires:int|null}> */
    private array $values = [];

    public int $mgetCalls = 0;

    public int $transactionCalls = 0;

    public int $setexCalls = 0;

    public bool $failTransactionCommand = false;

    private bool $transactional = false;

    /** @var list<bool> */
    private array $transactionResults = [];

    /** @param string|list<string> $keys */
    public function del(string|array $keys): int
    {
        $deleted = 0;
        foreach (is_array($keys) ? $keys : [$keys] as $key) {
            if (isset($this->values[$key])) {
                unset($this->values[$key]);
                $deleted++;
            }
        }

        return $deleted;
    }

    /** @param list<string> $arguments */
    public function eval(string $script, array $arguments, int $numKeys): mixed
    {
        if ($numKeys !== 2 || !isset($arguments[0], $arguments[1])) {
            return false;
        }
        $generationKey = $arguments[0];
        $dataKey = $arguments[1];

        if (str_contains($script, 'cachelayer:atomic-get-and-delete')) {
            $generation = $this->get($generationKey);
            $value = $this->get($dataKey);
            if ($value !== false) {
                unset($this->values[$dataKey]);
            }

            return [$generation, $value];
        }

        if (str_contains($script, 'cachelayer:atomic-compare-and-set')) {
            $expectedGeneration = $arguments[2] ?? null;
            $expectedExisting = $arguments[3] ?? null;
            $blob = $arguments[4] ?? null;
            $ttl = (int) ($arguments[5] ?? 0);
            if ($expectedGeneration === null || $expectedExisting === null || $blob === null) {
                return false;
            }
            if ($this->get($generationKey) !== $expectedGeneration) {
                return -1;
            }
            if ($this->get($dataKey) !== $expectedExisting) {
                return 0;
            }

            $this->values[$dataKey] = [
                'value' => $blob,
                'expires' => $ttl > 0 ? time() + $ttl : null,
            ];

            return 1;
        }

        if (!str_contains($script, 'cachelayer:atomic-set-if-absent')) {
            return false;
        }
        $expectedGeneration = $arguments[2] ?? null;
        $blob = $arguments[3] ?? null;
        $ttl = (int) ($arguments[4] ?? 0);
        $replaceStale = ($arguments[5] ?? '0') === '1';
        $expectedExisting = $arguments[6] ?? null;
        if ($expectedGeneration === null || $blob === null) {
            return false;
        }
        if ($this->get($generationKey) !== $expectedGeneration) {
            return -1;
        }
        $current = $this->get($dataKey);
        if ($current !== false && (!$replaceStale || $current !== $expectedExisting)) {
            return 0;
        }

        $this->values[$dataKey] = [
            'value' => $blob,
            'expires' => $ttl > 0 ? time() + $ttl : null,
        ];

        return 1;
    }

    public function exists(string $key): int
    {
        return $this->get($key) === false ? 0 : 1;
    }

    public function get(string $key): string|false
    {
        $this->prune($key);

        return $this->values[$key]['value'] ?? false;
    }

    public function incr(string $key): int
    {
        $current = $this->get($key);
        $next = ($current === false ? 0 : (int) $current) + 1;
        $this->set($key, (string) $next);

        return $next;
    }

    /**
     * @param list<string> $keys
     * @return list<string|false>
     */
    public function mget(array $keys): array
    {
        $this->mgetCalls++;

        return array_map(fn(string $key): string|false => $this->get($key), $keys);
    }

    /** @param array<string, string> $values */
    public function mset(array $values): bool
    {
        foreach ($values as $key => $value) {
            $this->set($key, $value);
        }

        return true;
    }

    /**
     * @throws RuntimeException
     */
    public function multi(int $mode): self
    {
        if ($mode !== 1) {
            throw new RuntimeException('Redis Cluster supports MULTI transactions only.');
        }
        $this->transactionCalls++;
        $this->transactional = true;
        $this->transactionResults = [];

        return $this;
    }

    /** @param list<string> $options */
    public function set(string $key, string $value, array $options = []): bool
    {
        if (in_array('nx', $options, true) && isset($this->values[$key])) {
            return false;
        }
        $this->values[$key] = ['value' => $value, 'expires' => null];

        return true;
    }

    public function setex(string $key, int $ttl, string $value): bool
    {
        $this->setexCalls++;
        $this->values[$key] = ['value' => $value, 'expires' => time() + max(1, $ttl)];
        if ($this->transactional) {
            $this->transactionResults[] = !$this->failTransactionCommand;
        }

        return true;
    }

    /** @return list<bool> */
    public function exec(): array
    {
        $this->transactional = false;

        return $this->transactionResults;
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->values);
    }

    public function dropGenerationFor(string $logicalKey): void
    {
        foreach (array_keys($this->values) as $physicalKey) {
            if (!str_ends_with($physicalKey, ':d:' . $logicalKey)) {
                continue;
            }
            $prefix = substr($physicalKey, 0, -strlen(':d:' . $logicalKey));
            unset($this->values[$prefix . ':m:generation']);
        }
    }

    private function prune(string $key): void
    {
        $expires = $this->values[$key]['expires'] ?? null;
        if ($expires !== null && $expires <= time()) {
            unset($this->values[$key]);
        }
    }
}
