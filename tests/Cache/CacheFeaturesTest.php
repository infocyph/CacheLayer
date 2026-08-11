<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\CacheLayer\Cache\CacheOptions;
use Infocyph\CacheLayer\Cache\Adapter\FileCacheAdapter;
use Infocyph\CacheLayer\Cache\Lock\LockHandle;
use Infocyph\CacheLayer\Cache\Lock\LockProviderInterface;
use Infocyph\CacheLayer\Cache\Metrics\InMemoryCacheMetricsCollector;
use Infocyph\CacheLayer\Exceptions\CacheInvalidArgumentException;

beforeEach(function () {
    $this->cacheDir = sys_get_temp_dir() . '/pest_cache_features_' . uniqid();
    $this->cache = Cache::file('features', $this->cacheDir);
});

afterEach(function () {
    if (!is_dir($this->cacheDir)) {
        return;
    }

    $it = new RecursiveDirectoryIterator($this->cacheDir, FilesystemIterator::SKIP_DOTS);
    $rim = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($rim as $file) {
        $path = $file->getRealPath();
        if ($path === false || !file_exists($path)) {
            continue;
        }
        $file->isDir() ? rmdir($path) : unlink($path);
    }
    if (is_dir($this->cacheDir)) {
        rmdir($this->cacheDir);
    }
});

test('setTagged + invalidateTag removes all tagged keys', function () {
    $this->cache->setTagged('k1', 'A', ['grp']);
    $this->cache->setTagged('k2', 'B', ['grp']);
    $this->cache->set('k3', 'C');

    expect($this->cache->invalidateTag('grp'))->toBeTrue()
        ->and($this->cache->get('k1'))->toBeNull()
        ->and($this->cache->get('k2'))->toBeNull()
        ->and($this->cache->get('k3'))->toBe('C');
});

test('remember caches once and supports tag invalidation', function () {
    $count = 0;

    $v1 = $this->cache->remember(
        'hot',
        function () use (&$count) {
            $count++;

            return 'payload';
        },
        30,
        ['hot-path'],
    );

    $v2 = $this->cache->remember(
        'hot',
        function () use (&$count) {
            $count++;

            return 'should-not-run';
        },
    );

    expect($v1)->toBe('payload')
        ->and($v2)->toBe('payload')
        ->and($count)->toBe(1)
        ->and($this->cache->invalidateTag('hot-path'))->toBeTrue()
        ->and($this->cache->get('hot'))->toBeNull();
});

test('get returns callable defaults without executing or caching them', function () {
    $count = 0;
    $default = function () use (&$count) {
        $count++;
        return 99;
    };

    expect($this->cache->get('compute', $default))->toBe($default)
        ->and($this->cache->has('compute'))->toBeFalse()
        ->and($count)->toBe(0);
});

test('invalidateTags removes value when duplicate tags are passed', function () {
    $this->cache->setTagged('dup', 'V', ['t1', 't1', 't2']);
    $this->cache->invalidateTags(['t2', 't1', 't1']);

    expect($this->cache->get('dup'))->toBeNull();
});

test('rejects empty tags in tag operations', function () {
    expect(fn() => $this->cache->invalidateTag('   '))
        ->toThrow(CacheInvalidArgumentException::class);

    expect(fn() => $this->cache->setTagged('x', 'y', ['ok', ' ']))
        ->toThrow(CacheInvalidArgumentException::class);
});

test('remember respects ttl argument expiry', function () {
    $this->cache->remember('short', fn() => 'value', 1);

    usleep(2_000_000);

    expect($this->cache->get('short'))->toBeNull();
});

test('cached null value does not fall back to default', function () {
    $this->cache->set('nullable', null);

    expect($this->cache->get('nullable', 'fallback'))->toBeNull()
        ->and($this->cache->hasItem('nullable'))->toBeTrue();
});

test('delete on missing key is treated as successful', function () {
    expect($this->cache->delete('never-there'))->toBeTrue()
        ->and($this->cache->deleteItem('never-there'))->toBeTrue()
        ->and($this->cache->deleteItems(['never-there', 'also-missing']))->toBeTrue();
});

test('tag generation invalidation marks prior entries stale', function () {
    $this->cache->setTagged('article', 'v1', ['content']);
    expect($this->cache->get('article'))->toBe('v1');

    $this->cache->invalidateTag('content');
    expect($this->cache->get('article'))->toBeNull();

    $this->cache->setTagged('article', 'v2', ['content']);
    expect($this->cache->get('article'))->toBe('v2');
});

test('valid user keys cannot collide with internal tag metadata', function () {
    $this->cache->set('tag.group', 'plain');
    $this->cache->setTagged('tagged', 'versioned', ['group']);
    $this->cache->invalidateTag('group');

    expect($this->cache->get('tag.group'))->toBe('plain')
        ->and($this->cache->get('tagged'))->toBeNull();
});

test('file tag rotations remain valid during concurrent updates', function () {
    if (!function_exists('pcntl_fork') || !function_exists('pcntl_exec')) {
        $this->markTestSkipped('pcntl is required for the concurrency test.');
    }

    $adapter = new FileCacheAdapter('features', $this->cacheDir);
    $before = $adapter->getTagGenerations(['concurrent'])['concurrent'];
    $children = [];
    for ($worker = 0; $worker < 4; $worker++) {
        $pid = pcntl_fork();
        if ($pid === 0) {
            $adapter = new FileCacheAdapter('features', $this->cacheDir);
            for ($increment = 0; $increment < 25; $increment++) {
                $adapter->rotateTagGenerations(['concurrent']);
            }
            pcntl_exec(PHP_BINARY, ['-r', '']);
            throw new RuntimeException('Unable to terminate concurrency-test worker.');
        }
        if ($pid > 0) {
            $children[] = $pid;
        }
    }
    foreach ($children as $pid) {
        pcntl_waitpid($pid, $status);
        expect(pcntl_wexitstatus($status))->toBe(0);
    }

    $generation = $adapter->getTagGenerations(['concurrent'])['concurrent'];
    expect($generation)->toMatch('/^[a-f0-9]{32}$/')
        ->and($generation)->not->toBe($before);
});

test('remember uses configured lock provider', function () {
    $calls = ['acquire' => 0, 'release' => 0];

    $provider = new class ($calls) implements LockProviderInterface {
        public function __construct(private array &$calls) {}

        public function acquire(string $key, float $waitSeconds, float $leaseSeconds = 30.0): ?LockHandle
        {
            if ($waitSeconds < 0 || $leaseSeconds <= 0) {
                return null;
            }
            $this->calls['acquire']++;

            return new LockHandle($key, 'tkn', leaseSeconds: $leaseSeconds);
        }

        public function refresh(?LockHandle $handle, float $leaseSeconds): bool
        {
            return $handle instanceof LockHandle && $leaseSeconds > 0;
        }

        public function release(?LockHandle $handle): void
        {
            if (!$handle instanceof LockHandle) {
                return;
            }
            $this->calls['release']++;
        }
    };

    $this->cache->setLockProvider($provider);
    $this->cache->remember('guarded', fn() => 123, 10);

    expect($calls['acquire'])->toBe(1)
        ->and($calls['release'])->toBe(1);
});

test('remember discards a value invalidated while its resolver runs', function () {
    $value = $this->cache->remember('raced', function (): string {
        $this->cache->invalidateTag('products');

        return 'stale';
    }, 300, ['products']);

    expect($value)->toBe('stale')
        ->and($this->cache->get('raced'))->toBeNull();
});

test('remember rechecks after lock timeout before resolving', function () {
    $cache = Cache::memory('timeout-recheck');
    $provider = new class($cache) implements LockProviderInterface {
        public function __construct(private Cache $cache) {}

        public function acquire(string $key, float $waitSeconds, float $leaseSeconds = 30.0): ?LockHandle
        {
            expect($key)->not->toBeEmpty()
                ->and($waitSeconds)->toBeGreaterThanOrEqual(0)
                ->and($leaseSeconds)->toBeGreaterThan(0);
            $this->cache->set('filled', 'winner', 300);

            return null;
        }

        public function refresh(?LockHandle $handle, float $leaseSeconds): bool
        {
            expect($handle)->toBeInstanceOf(LockHandle::class)
                ->and($leaseSeconds)->toBeGreaterThan(0);

            return false;
        }

        public function release(?LockHandle $handle): void {}
    };
    $runs = 0;
    $cache->setLockProvider($provider);

    expect($cache->remember('filled', function () use (&$runs): string {
        ++$runs;

        return 'loser';
    }))->toBe('winner')
        ->and($runs)->toBe(0);
});

test('remember never stores after lock ownership is lost', function () {
    $cache = Cache::memory('lost-lock');
    $provider = new class implements LockProviderInterface {
        public function acquire(string $key, float $waitSeconds, float $leaseSeconds = 30.0): ?LockHandle
        {
            expect($waitSeconds)->toBeGreaterThanOrEqual(0);

            return new LockHandle($key, 'owner', leaseSeconds: $leaseSeconds);
        }

        public function refresh(?LockHandle $handle, float $leaseSeconds): bool
        {
            expect($handle)->toBeInstanceOf(LockHandle::class)
                ->and($leaseSeconds)->toBeGreaterThan(0);

            return false;
        }

        public function release(?LockHandle $handle): void {}
    };
    $cache->setLockProvider($provider);

    expect($cache->remember('lost', fn(): string => 'computed', 300))->toBe('computed')
        ->and($cache->get('lost'))->toBeNull()
        ->and($cache->exportMetrics()['array']['remember_discarded_after_lock_loss'] ?? 0)->toBe(1);
});

test('remember lock identities include the cache namespace', function () {
    $keys = [];
    $provider = new class($keys) implements LockProviderInterface {
        public function __construct(private array &$keys) {}

        public function acquire(string $key, float $waitSeconds, float $leaseSeconds = 30.0): ?LockHandle
        {
            expect($waitSeconds)->toBeGreaterThanOrEqual(0);
            $this->keys[] = $key;

            return new LockHandle($key, bin2hex(random_bytes(16)), leaseSeconds: $leaseSeconds);
        }

        public function refresh(?LockHandle $handle, float $leaseSeconds): bool
        {
            expect($handle)->toBeInstanceOf(LockHandle::class)
                ->and($leaseSeconds)->toBeGreaterThan(0);

            return true;
        }

        public function release(?LockHandle $handle): void {}
    };
    Cache::memory('tenant-a')->setLockProvider($provider)->remember('same', fn(): int => 1);
    Cache::memory('tenant-b')->setLockProvider($provider)->remember('same', fn(): int => 2);

    expect($keys)->toHaveCount(2)
        ->and($keys[0])->not->toBe($keys[1]);
});

test('metrics collector exports hit and miss counters', function () {
    $collector = new InMemoryCacheMetricsCollector();
    $this->cache->setMetricsCollector($collector);

    $this->cache->get('x');
    $this->cache->set('x', 1);
    $this->cache->get('x');

    $metrics = $this->cache->exportMetrics();
    $adapter = 'file';

    expect($metrics[$adapter]['get_miss'] ?? 0)->toBeGreaterThanOrEqual(1)
        ->and($metrics[$adapter]['get_hit'] ?? 0)->toBeGreaterThanOrEqual(1)
        ->and($metrics[$adapter]['set'] ?? 0)->toBeGreaterThanOrEqual(1);
});

test('metrics export hook receives snapshot', function () {
    $snapshot = null;

    $this->cache
        ->setMetricsExportHook(function (array $metrics) use (&$snapshot): void {
            $snapshot = $metrics;
        });

    $this->cache->get('hook-miss');
    $exported = $this->cache->exportMetrics();

    expect($snapshot)->toBeArray()
        ->and($snapshot)->toBe($exported);
});

test('payload compression can be enabled without changing values', function () {
    $payload = str_repeat('cache-layer-payload-', 128);
    $cache = Cache::file(
        'compressed-features',
        $this->cacheDir,
        new CacheOptions(compressionThreshold: 128, compressionLevel: 6),
    );
    $cache->set('big', $payload);

    expect($cache->get('big'))->toBe($payload);
});
