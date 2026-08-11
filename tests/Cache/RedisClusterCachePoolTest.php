<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cache\Cache;

beforeEach(function () {
    $this->cluster = new class
    {
        /** @var array<string, array{value:string,expires:int|null}> */
        private array $values = [];

        public int $mgetCalls = 0;

        public int $pipelineCalls = 0;

        public int $setexCalls = 0;

        public bool $failPipelineCommand = false;

        private bool $pipelined = false;

        /** @var list<bool> */
        private array $pipelineResults = [];

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
            $next = (int) ($this->get($key) ?: 0) + 1;
            $this->set($key, (string) $next);

            return $next;
        }

        /** @param list<string> $keys */
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

        public function multi(int $mode): self
        {
            $this->pipelineCalls++;
            $this->pipelined = $mode > 0;
            $this->pipelineResults = [];

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
            if ($this->pipelined) {
                $this->pipelineResults[] = !$this->failPipelineCommand;
            }

            return true;
        }

        /** @return list<bool> */
        public function exec(): array
        {
            $this->pipelined = false;

            return $this->pipelineResults;
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
    };

    $this->cache = Cache::redisCluster(
        'cluster-tests',
        ['127.0.0.1:7000'],
        1.0,
        1.0,
        false,
        $this->cluster,
    );
});

test('redis cluster adapter bulk-fetches cross-slot values', function () {
    $this->cache->setMultiple(['alpha' => 'A', 'beta' => 'B', 'gamma' => 'C']);
    $before = $this->cluster->mgetCalls;

    expect($this->cache->getMultiple(['alpha', 'missing', 'beta']))
        ->toBe(['alpha' => 'A', 'missing' => null, 'beta' => 'B'])
        ->and($this->cluster->mgetCalls)->toBeGreaterThan($before);
});

test('redis cluster adapter honors ttl', function () {
    $this->cache->set('ttl', 'v', 1);
    usleep(2_000_000);

    expect($this->cache->get('ttl'))->toBeNull();
});

test('redis cluster adapter rejects a partial expiring pipeline write', function () {
    $this->cluster->failPipelineCommand = true;

    expect($this->cache->setMultiple(['first' => 1, 'second' => 2], 60))->toBeFalse();
});

test('redis cluster groups expiring bulk writes into bounded bucket pipelines', function () {
    $values = [];
    $buckets = [];
    for ($index = 0; $index < 100; $index++) {
        $key = 'bucketed.' . $index;
        $values[$key] = $index;
        $buckets[hexdec(substr(hash('xxh3', $key), 0, 8)) % 128] = true;
    }

    expect($this->cache->setMultiple($values, 60))->toBeTrue()
        ->and($this->cluster->setexCalls)->toBe(100)
        ->and($this->cluster->pipelineCalls)->toBe(count($buckets));
});

test('redis cluster clear rotates bucket generations without a permanent key index', function () {
    $this->cache->setMultiple(['a' => 1, 'b' => 2]);
    $this->cache->clear();

    expect($this->cache->getMultiple(['a', 'b']))->toBe(['a' => null, 'b' => null])
        ->and(implode('|', $this->cluster->keys()))->not->toContain('__keys');
});

test('missing bucket generation cannot resurrect data written before clear', function () {
    $this->cache->set('old', 'stale');
    $this->cache->clear();
    $this->cluster->dropGenerationFor('old');

    expect($this->cache->get('old'))->toBeNull();
});
