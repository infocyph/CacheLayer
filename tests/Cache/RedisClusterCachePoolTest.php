<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cache\Cache;

beforeEach(function () {
    $this->cluster = new class
    {
        /** @var array<string, array{value:string,expires:int|null}> */
        private array $kv = [];

        /** @var array<string, array<string, bool>> */
        private array $sets = [];

        public function del(string|array $keys): int
        {
            $keys = is_array($keys) ? $keys : [$keys];
            $deleted = 0;
            foreach ($keys as $key) {
                if (isset($this->kv[$key])) {
                    unset($this->kv[$key]);
                    $deleted++;
                }
                if (isset($this->sets[$key])) {
                    unset($this->sets[$key]);
                    $deleted++;
                }
            }

            return $deleted;
        }

        public function exists(string $key): int
        {
            $this->pruneKey($key);

            return isset($this->kv[$key]) ? 1 : 0;
        }

        public function get(string $key): string|false
        {
            $this->pruneKey($key);

            return $this->kv[$key]['value'] ?? false;
        }

        public function sAdd(string $key, string $member): int
        {
            $exists = isset($this->sets[$key][$member]);
            $this->sets[$key][$member] = true;

            return $exists ? 0 : 1;
        }

        public function sCard(string $key): int
        {
            return count($this->sets[$key] ?? []);
        }

        public function sMembers(string $key): array
        {
            return array_keys($this->sets[$key] ?? []);
        }

        public function sRem(string $key, string $member): int
        {
            if (! isset($this->sets[$key][$member])) {
                return 0;
            }

            unset($this->sets[$key][$member]);

            return 1;
        }

        public function set(string $key, string $value): bool
        {
            $this->kv[$key] = ['value' => $value, 'expires' => null];

            return true;
        }

        public function setex(string $key, int $ttl, string $value): bool
        {
            $this->kv[$key] = ['value' => $value, 'expires' => time() + max(1, $ttl)];

            return true;
        }

        private function pruneKey(string $key): void
        {
            if (! isset($this->kv[$key])) {
                return;
            }

            $expires = $this->kv[$key]['expires'];
            if ($expires !== null && $expires <= time()) {
                unset($this->kv[$key]);
            }
        }
    };

    $this->cache = Cache::redisCluster('cluster-tests', ['127.0.0.1:7000'], 1.0, 1.0, false, $this->cluster);
});

test('redis cluster adapter stores and retrieves values', function () {
    $this->cache->set('k', 'value');

    expect($this->cache->get('k'))->toBe('value')
        ->and($this->cache->count())->toBe(1);
});

test('redis cluster adapter honors ttl', function () {
    $this->cache->set('ttl', 'v', 1);
    usleep(2_000_000);

    expect($this->cache->get('ttl'))->toBeNull();
});

test('redis cluster adapter clear removes cached values', function () {
    $this->cache->set('a', 1);
    $this->cache->set('b', 2);

    $this->cache->clear();

    expect($this->cache->count())->toBe(0)
        ->and($this->cache->get('a'))->toBeNull()
        ->and($this->cache->get('b'))->toBeNull();
});
