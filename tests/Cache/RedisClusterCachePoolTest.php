<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cache\AtomicCacheInterface;
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

        /** @param list<mixed> $arguments */
        public function eval(string $script, array $arguments, int $numKeys): mixed
        {
            if ($numKeys !== 2 || !is_string($arguments[0] ?? null) || !is_string($arguments[1] ?? null)) {
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

            if (!str_contains($script, 'cachelayer:atomic-set-if-absent')) {
                return false;
            }
            $expectedGeneration = $arguments[2] ?? null;
            $blob = $arguments[3] ?? null;
            $ttl = (int) ($arguments[4] ?? 0);
            $replaceStale = ($arguments[5] ?? '0') === '1';
            $expectedExisting = $arguments[6] ?? null;
            if (!is_string($expectedGeneration) || !is_string($blob)) {
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

test('redis cluster exposes atomic capability with one-winner semantics', function () {
    $atomic = $this->cache->atomic();

    expect($atomic)->toBeInstanceOf(AtomicCacheInterface::class)
        ->and($atomic->setIfAbsent('claim', 'first', 30))->toBeTrue()
        ->and($atomic->setIfAbsent('claim', 'second', 30))->toBeFalse()
        ->and($this->cache->get('claim'))->toBe('first');
});

test('redis cluster atomic consume returns a value once', function () {
    $atomic = $this->cache->atomic();
    expect($atomic)->not->toBeNull();
    $this->cache->set('consume', ['ok' => true], 30);

    expect($atomic->getAndDelete('consume', 'missing'))->toBe(['ok' => true])
        ->and($atomic->getAndDelete('consume', 'missing'))->toBe('missing');
});

test('redis cluster atomic set replaces data invalidated by clear', function () {
    $atomic = $this->cache->atomic();
    expect($atomic)->not->toBeNull();
    $this->cache->set('claim', 'stale', 30);
    $this->cache->clear();

    expect($atomic->setIfAbsent('claim', 'fresh', 30))->toBeTrue()
        ->and($this->cache->get('claim'))->toBe('fresh');
});

test('redis cluster atomic consume rejects data invalidated by clear', function () {
    $atomic = $this->cache->atomic();
    expect($atomic)->not->toBeNull();
    $this->cache->set('consume', 'stale', 30);
    $this->cache->clear();

    expect($atomic->getAndDelete('consume', 'missing'))->toBe('missing')
        ->and($this->cache->get('consume'))->toBeNull();
});

test('redis cluster atomic ttl expires before the next claim', function () {
    $atomic = $this->cache->atomic();
    expect($atomic)->not->toBeNull()
        ->and($atomic->setIfAbsent('claim', 'first', 1))->toBeTrue();
    usleep(2_000_000);

    expect($atomic->setIfAbsent('claim', 'second', 30))->toBeTrue()
        ->and($this->cache->get('claim'))->toBe('second');
});
