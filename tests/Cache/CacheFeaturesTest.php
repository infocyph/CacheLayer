<?php

use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\CacheLayer\Cache\Lock\LockHandle;
use Infocyph\CacheLayer\Cache\Lock\LockProviderInterface;
use Infocyph\CacheLayer\Cache\Metrics\InMemoryCacheMetricsCollector;
use Infocyph\CacheLayer\Exceptions\CacheInvalidArgumentException;

beforeEach(function () {
    $this->cacheDir = sys_get_temp_dir().'/pest_cache_features_'.uniqid();
    $this->cache = Cache::file('features', $this->cacheDir);
});

afterEach(function () {
    if (! is_dir($this->cacheDir)) {
        return;
    }

    $it = new RecursiveDirectoryIterator($this->cacheDir, FilesystemIterator::SKIP_DOTS);
    $rim = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($rim as $file) {
        $path = $file->getRealPath();
        if ($path === false || ! file_exists($path)) {
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
        function ($item) use (&$count) {
            $count++;
            $item->expiresAfter(30);

            return 'payload';
        },
        null,
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

test('get callable path still computes once on miss', function () {
    $count = 0;

    $a = $this->cache->get('compute', function ($item) use (&$count) {
        $count++;
        $item->expiresAfter(30);

        return 99;
    });
    $b = $this->cache->get('compute', function () use (&$count) {
        $count++;

        return 11;
    });

    expect($a)->toBe(99)
        ->and($b)->toBe(99)
        ->and($count)->toBe(1);
});

test('invalidateTags removes value when duplicate tags are passed', function () {
    $this->cache->setTagged('dup', 'V', ['t1', 't1', 't2']);
    $this->cache->invalidateTags(['t2', 't1', 't1']);

    expect($this->cache->get('dup'))->toBeNull();
});

test('rejects empty tags in tag operations', function () {
    expect(fn () => $this->cache->invalidateTag('   '))
        ->toThrow(CacheInvalidArgumentException::class);

    expect(fn () => $this->cache->setTagged('x', 'y', ['ok', ' ']))
        ->toThrow(CacheInvalidArgumentException::class);
});

test('remember respects ttl argument expiry', function () {
    $this->cache->remember('short', fn ($item) => 'value', 1);

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

test('tag version invalidation marks prior entries stale', function () {
    $this->cache->setTagged('article', 'v1', ['content']);
    expect($this->cache->get('article'))->toBe('v1');

    $this->cache->invalidateTag('content');
    expect($this->cache->get('article'))->toBeNull();

    $this->cache->setTagged('article', 'v2', ['content']);
    expect($this->cache->get('article'))->toBe('v2');
});

test('remember uses configured lock provider', function () {
    $calls = ['acquire' => 0, 'release' => 0];

    $provider = new class($calls) implements LockProviderInterface
    {
        public function __construct(private array &$calls) {}

        public function acquire(string $key, float $waitSeconds): ?LockHandle
        {
            $this->calls['acquire']++;

            return new LockHandle($key, 'tkn');
        }

        public function release(?LockHandle $handle): void
        {
            $this->calls['release']++;
        }
    };

    $this->cache->setLockProvider($provider);
    $this->cache->remember('guarded', fn () => 123, 10);

    expect($calls['acquire'])->toBe(1)
        ->and($calls['release'])->toBe(1);
});

test('metrics collector exports hit and miss counters', function () {
    $collector = new InMemoryCacheMetricsCollector;
    $this->cache->setMetricsCollector($collector);

    $this->cache->get('x');
    $this->cache->set('x', 1);
    $this->cache->get('x');

    $metrics = $this->cache->exportMetrics();
    $adapter = 'file';

    expect($metrics[$adapter]['miss'] ?? 0)->toBeGreaterThanOrEqual(1)
        ->and($metrics[$adapter]['hit'] ?? 0)->toBeGreaterThanOrEqual(1)
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

    $this->cache->configurePayloadCompression(128, 6);
    $this->cache->set('big', $payload);

    expect($this->cache->get('big'))->toBe($payload);

    $this->cache->configurePayloadCompression(null);
});
