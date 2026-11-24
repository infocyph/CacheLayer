<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\CacheLayer\Cache\CacheOptions;
use Infocyph\CacheLayer\Cache\Adapter\FileCacheAdapter;
use Infocyph\CacheLayer\Cache\Lock\LockHandle;
use Infocyph\CacheLayer\Cache\Lock\LockProviderInterface;
use Infocyph\CacheLayer\Cache\Metrics\InMemoryCacheMetricsCollector;
use Infocyph\CacheLayer\Exceptions\CacheInvalidArgumentException;

$featuresFixtureCacheDir = '';
$featuresFixtureCache = Cache::nullStore();

beforeEach(function () use (&$featuresFixtureCacheDir, &$featuresFixtureCache): void {
    $featuresFixtureCacheDir = sys_get_temp_dir() . '/pest_cache_features_' . uniqid();
    $featuresFixtureCache = Cache::file('features', $featuresFixtureCacheDir);
});

afterEach(function () use (&$featuresFixtureCacheDir): void {
    if (!is_dir($featuresFixtureCacheDir)) {
        return;
    }

    $it = new RecursiveDirectoryIterator($featuresFixtureCacheDir, FilesystemIterator::SKIP_DOTS);
    $rim = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($rim as $file) {
        if (!$file instanceof SplFileInfo) {
            throw new RuntimeException('Unexpected filesystem iterator entry.');
        }
        $path = $file->getRealPath();
        if ($path === false || !file_exists($path)) {
            continue;
        }
        $file->isDir() ? rmdir($path) : unlink($path);
    }
    if (is_dir($featuresFixtureCacheDir)) {
        rmdir($featuresFixtureCacheDir);
    }
});

test('setTagged + invalidateTag removes all tagged keys', function () use (&$featuresFixtureCache): void {
    $featuresFixtureCache->setTagged('k1', 'A', ['grp']);
    $featuresFixtureCache->setTagged('k2', 'B', ['grp']);
    $featuresFixtureCache->set('k3', 'C');

    expect($featuresFixtureCache->invalidateTag('grp'))->toBeTrue()
        ->and($featuresFixtureCache->get('k1'))->toBeNull()
        ->and($featuresFixtureCache->get('k2'))->toBeNull()
        ->and($featuresFixtureCache->get('k3'))->toBe('C');
});

test('remember caches once and supports tag invalidation', function () use (&$featuresFixtureCache): void {
    $count = 0;

    $v1 = $featuresFixtureCache->remember(
        'hot',
        function () use (&$count) {
            $count++;

            return 'payload';
        },
        30,
        ['hot-path'],
    );

    $v2 = $featuresFixtureCache->remember(
        'hot',
        function () use (&$count) {
            $count++;

            return 'should-not-run';
        },
    );

    expect($v1)->toBe('payload')
        ->and($v2)->toBe('payload')
        ->and($count)->toBe(1)
        ->and($featuresFixtureCache->invalidateTag('hot-path'))->toBeTrue()
        ->and($featuresFixtureCache->get('hot'))->toBeNull();
});

test('get returns callable defaults without executing or caching them', function () use (&$featuresFixtureCache): void {
    $count = 0;
    $default = function () use (&$count): int {
        $count++;
        return 99;
    };

    expect($featuresFixtureCache->get('compute', $default))->toBe($default)
        ->and($featuresFixtureCache->has('compute'))->toBeFalse()
        ->and($count)->toBe(0);
});

test('invalidateTags removes value when duplicate tags are passed', function () use (&$featuresFixtureCache): void {
    $featuresFixtureCache->setTagged('dup', 'V', ['t1', 't1', 't2']);
    $featuresFixtureCache->invalidateTags(['t2', 't1', 't1']);

    expect($featuresFixtureCache->get('dup'))->toBeNull();
});

test('rejects empty tags in tag operations', function () use (&$featuresFixtureCache): void {
    expect(fn() => $featuresFixtureCache->invalidateTag('   '))
        ->toThrow(CacheInvalidArgumentException::class);

    expect(fn() => $featuresFixtureCache->setTagged('x', 'y', ['ok', ' ']))
        ->toThrow(CacheInvalidArgumentException::class);
});

test('remember respects ttl argument expiry', function () use (&$featuresFixtureCache): void {
    $featuresFixtureCache->remember('short', fn() => 'value', 1);

    usleep(2_000_000);

    expect($featuresFixtureCache->get('short'))->toBeNull();
});

test('cached null value does not fall back to default', function () use (&$featuresFixtureCache): void {
    $featuresFixtureCache->set('nullable', null);

    expect($featuresFixtureCache->get('nullable', 'fallback'))->toBeNull()
        ->and($featuresFixtureCache->hasItem('nullable'))->toBeTrue();
});

test('delete on missing key is treated as successful', function () use (&$featuresFixtureCache): void {
    expect($featuresFixtureCache->delete('never-there'))->toBeTrue()
        ->and($featuresFixtureCache->deleteItem('never-there'))->toBeTrue()
        ->and($featuresFixtureCache->deleteItems(['never-there', 'also-missing']))->toBeTrue();
});

test('tag generation invalidation marks prior entries stale', function () use (&$featuresFixtureCache): void {
    $featuresFixtureCache->setTagged('article', 'v1', ['content']);
    expect($featuresFixtureCache->get('article'))->toBe('v1');

    $featuresFixtureCache->invalidateTag('content');
    expect($featuresFixtureCache->get('article'))->toBeNull();

    $featuresFixtureCache->setTagged('article', 'v2', ['content']);
    expect($featuresFixtureCache->get('article'))->toBe('v2');
});

test('valid user keys cannot collide with internal tag metadata', function () use (&$featuresFixtureCache): void {
    $featuresFixtureCache->set('tag.group', 'plain');
    $featuresFixtureCache->setTagged('tagged', 'versioned', ['group']);
    $featuresFixtureCache->invalidateTag('group');

    expect($featuresFixtureCache->get('tag.group'))->toBe('plain')
        ->and($featuresFixtureCache->get('tagged'))->toBeNull();
});

test('file tag rotations remain valid during concurrent updates', function () use (&$featuresFixtureCacheDir): void {
    expect(function_exists('pcntl_fork'))->toBeTrue()
        ->and(function_exists('pcntl_exec'))->toBeTrue();

    $adapter = new FileCacheAdapter('features', $featuresFixtureCacheDir);
    $before = $adapter->getTagGenerations(['concurrent'])['concurrent'];
    $children = [];
    for ($worker = 0; $worker < 4; $worker++) {
        $pid = pcntl_fork();
        if ($pid === 0) {
            $adapter = new FileCacheAdapter('features', $featuresFixtureCacheDir);
            for ($increment = 0; $increment < 25; $increment++) {
                $adapter->rotateTagGenerations(['concurrent']);
            }
            pcntl_exec(PHP_BINARY, ['-r', '']);
            throw new RuntimeException('Unable to terminate concurrency-test worker.');
        }
        if ($pid < 0) {
            throw new RuntimeException('Unable to fork a tag rotation worker.');
        }
        $children[] = $pid;
    }
    foreach ($children as $pid) {
        $status = 0;
        if (pcntl_waitpid($pid, $status) !== $pid || !pcntl_wifexited($status)) {
            throw new RuntimeException('Tag rotation worker did not exit normally.');
        }
        expect(pcntl_wexitstatus($status))->toBe(0);
    }

    $generation = $adapter->getTagGenerations(['concurrent'])['concurrent'];
    expect($generation)->toMatch('/^[a-f0-9]{32}$/')
        ->and($generation)->not->toBe($before);
});

test('remember uses configured lock provider', function () use (&$featuresFixtureCache): void {
    $calls = ['acquire' => 0, 'release' => 0];

    $provider = new class ($calls) implements LockProviderInterface {
        /** @param array{acquire:int,release:int} $calls */
        public function __construct(private array &$calls) {}

        #[\Override]
        public function acquire(string $key, float $waitSeconds, float $leaseSeconds = 30.0): ?LockHandle
        {
            if ($waitSeconds < 0 || $leaseSeconds <= 0) {
                return null;
            }
            $this->calls['acquire']++;

            return new LockHandle($key, 'tkn', leaseSeconds: $leaseSeconds);
        }

        #[\Override]
        public function refresh(?LockHandle $handle, float $leaseSeconds): bool
        {
            return $handle instanceof LockHandle && $leaseSeconds > 0;
        }

        #[\Override]
        public function release(?LockHandle $handle): void
        {
            if (!$handle instanceof LockHandle) {
                return;
            }
            $this->calls['release']++;
        }
    };

    $featuresFixtureCache->setLockProvider($provider);
    $featuresFixtureCache->remember('guarded', fn() => 123, 10);

    expect($calls['acquire'])->toBe(1)
        ->and($calls['release'])->toBe(1);
});

test('remember discards a value invalidated while its resolver runs', function () use (&$featuresFixtureCache): void {
    $value = $featuresFixtureCache->remember('raced', function () use ($featuresFixtureCache): string {
        $featuresFixtureCache->invalidateTag('products');

        return 'stale';
    }, 300, ['products']);

    expect($value)->toBe('stale')
        ->and($featuresFixtureCache->get('raced'))->toBeNull();
});

test('remember rechecks after lock timeout before resolving', function (): void {
    $cache = Cache::memory('timeout-recheck');
    $provider = new class($cache) implements LockProviderInterface {
        public function __construct(private Cache $cache) {}

        #[\Override]
        public function acquire(string $key, float $waitSeconds, float $leaseSeconds = 30.0): ?LockHandle
        {
            expect($key)->not->toBeEmpty()
                ->and($waitSeconds)->toBeGreaterThanOrEqual(0)
                ->and($leaseSeconds)->toBeGreaterThan(0);
            $this->cache->set('filled', 'winner', 300);

            return null;
        }

        #[\Override]
        public function refresh(?LockHandle $handle, float $leaseSeconds): bool
        {
            expect($handle)->toBeInstanceOf(LockHandle::class)
                ->and($leaseSeconds)->toBeGreaterThan(0);

            return false;
        }

        #[\Override]
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

test('remember never stores after lock ownership is lost', function (): void {
    $cache = Cache::memory('lost-lock');
    $provider = new class implements LockProviderInterface {
        #[\Override]
        public function acquire(string $key, float $waitSeconds, float $leaseSeconds = 30.0): LockHandle
        {
            expect($waitSeconds)->toBeGreaterThanOrEqual(0);

            return new LockHandle($key, 'owner', leaseSeconds: $leaseSeconds);
        }

        #[\Override]
        public function refresh(?LockHandle $handle, float $leaseSeconds): bool
        {
            expect($handle)->toBeInstanceOf(LockHandle::class)
                ->and($leaseSeconds)->toBeGreaterThan(0);

            return false;
        }

        #[\Override]
        public function release(?LockHandle $handle): void {}
    };
    $cache->setLockProvider($provider);

    expect($cache->remember('lost', fn(): string => 'computed', 300))->toBe('computed')
        ->and($cache->get('lost'))->toBeNull()
        ->and($cache->exportMetrics()['array']['remember_discarded_after_lock_loss'] ?? 0)->toBe(1);
});

test('remember lock identities include the cache namespace', function (): void {
    $keys = [];
    $provider = new class($keys) implements LockProviderInterface {
        /** @param list<string> $keys */
        public function __construct(private array &$keys) {}

        #[\Override]
        public function acquire(string $key, float $waitSeconds, float $leaseSeconds = 30.0): LockHandle
        {
            expect($waitSeconds)->toBeGreaterThanOrEqual(0);
            $this->keys[] = $key;

            return new LockHandle($key, bin2hex(random_bytes(16)), leaseSeconds: $leaseSeconds);
        }

        #[\Override]
        public function refresh(?LockHandle $handle, float $leaseSeconds): bool
        {
            expect($handle)->toBeInstanceOf(LockHandle::class)
                ->and($leaseSeconds)->toBeGreaterThan(0);

            return true;
        }

        #[\Override]
        public function release(?LockHandle $handle): void {}
    };
    Cache::memory('tenant-a')->setLockProvider($provider)->remember('same', fn(): int => 1);
    Cache::memory('tenant-b')->setLockProvider($provider)->remember('same', fn(): int => 2);

    expect($keys)->toHaveCount(2)
        ->and($keys[0])->not->toBe($keys[1]);
});

test('metrics collector exports hit and miss counters', function () use (&$featuresFixtureCache): void {
    $collector = new InMemoryCacheMetricsCollector();
    $featuresFixtureCache->setMetricsCollector($collector);

    $featuresFixtureCache->get('x');
    $featuresFixtureCache->set('x', 1);
    $featuresFixtureCache->get('x');

    $metrics = $featuresFixtureCache->exportMetrics();
    $adapter = 'file';

    expect($metrics[$adapter]['get_miss'] ?? 0)->toBeGreaterThanOrEqual(1)
        ->and($metrics[$adapter]['get_hit'] ?? 0)->toBeGreaterThanOrEqual(1)
        ->and($metrics[$adapter]['set'] ?? 0)->toBeGreaterThanOrEqual(1);
});

test('metrics export hook receives snapshot', function () use (&$featuresFixtureCache): void {
    $snapshot = null;

    $featuresFixtureCache
        ->setMetricsExportHook(function (array $metrics) use (&$snapshot): void {
            $snapshot = $metrics;
        });

    $featuresFixtureCache->get('hook-miss');
    $exported = $featuresFixtureCache->exportMetrics();

    expect($snapshot)->toBeArray()
        ->and($snapshot)->toBe($exported);
});

test('payload compression can be enabled without changing values', function () use (&$featuresFixtureCacheDir): void {
    $payload = str_repeat('cache-layer-payload-', 128);
    $cache = Cache::file(
        'compressed-features',
        $featuresFixtureCacheDir,
        new CacheOptions(compressionThreshold: 128, compressionLevel: 6),
    );
    $cache->set('big', $payload);

    expect($cache->get('big'))->toBe($payload);
});
