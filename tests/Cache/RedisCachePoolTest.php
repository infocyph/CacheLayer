<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Tests\Cache;

/**
 * tests/RedisCachePoolTest.php
 *
 * Executes the same behavioural checks as the File/APCu/Memcache/SQLite
 * suites, but against the Redis adapter.  The suite self-skips when:
 *   • phpredis extension is not loaded, or
 *   • no Redis server answers at 127.0.0.1:6379.
 */

use Infocyph\CacheLayer\Cache\AtomicCacheInterface;
use Closure;
use InvalidArgumentException;
use Redis;
use RuntimeException;
use Throwable;
use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\CacheLayer\Cache\CacheOptions;
use Infocyph\CacheLayer\Cache\Item\CacheItem;
use Infocyph\CacheLayer\Counter\AtomicCounters;
use Infocyph\CacheLayer\Counter\Exception\AtomicCounterException;
use Infocyph\CacheLayer\Exceptions\CacheInvalidArgumentException;
use Infocyph\CacheLayer\Support\RedisValueGuard;
use Infocyph\CacheLayer\Tests\Support\AtomicCounterProcessProbe;

/* ── skip whole file when Redis unavailable ───────────────────────── */
if (! class_exists(Redis::class)) {
    throw new RuntimeException('phpredis is required for the configured cache test matrix.');
}

$redisHost = \Infocyph\CacheLayer\Tests\Support\CacheFixture::environment('IC_REDIS_HOST') ?? \Infocyph\CacheLayer\Tests\Support\CacheFixture::environment('CACHELAYER_REDIS_HOST') ?? '127.0.0.1';
$redisPort = (int) (\Infocyph\CacheLayer\Tests\Support\CacheFixture::environment('IC_REDIS_PORT') ?? \Infocyph\CacheLayer\Tests\Support\CacheFixture::environment('CACHELAYER_REDIS_PORT') ?? '6379');
$redisPassword = getenv('IC_REDIS_PASSWORD');
if ($redisPassword === false) {
    $redisPassword = getenv('IC_SERVICE_PASSWORD');
}
if ($redisPassword === false) {
    $redisPassword = getenv('CACHELAYER_REDIS_PASSWORD');
}
if ($redisPassword === false) {
    $redisPassword = '';
}

try {
    $probe = new Redis;
    $probe->connect($redisHost, $redisPort, 0.5);
    if ($redisPassword !== '') {
        $probe->auth($redisPassword);
    }
    $probe->ping();
} catch (Throwable $failure) {
    throw new RuntimeException('Redis service is required for the configured cache test matrix.', 0, $failure);
}

$finishForkedTest = static function (bool $success): never {
    pcntl_exec('/bin/sh', ['-c', $success ? 'true' : 'false']);

    throw new RuntimeException('Unable to terminate forked Redis test process.');
};

/* ── bootstrap / teardown ────────────────────────────────────────── */
$redisFixtureCache = Cache::nullStore();
$redisFixtureRedisClient = new Redis();

beforeEach(function () use ($redisHost, $redisPort, $redisPassword, &$redisFixtureCache, &$redisFixtureRedisClient): void {
    $client = new Redis;
    $client->connect($redisHost, $redisPort);
    if ($redisPassword !== '') {
        $client->auth($redisPassword);
    }
    $client->flushDB();                               // fresh DB 0

    $redisFixtureRedisClient = $client;
    $redisFixtureCache = Cache::redis(
        'tests',
        sprintf('redis://%s:%d', $redisHost, $redisPort),
        $client,
        new CacheOptions(allowClosures: true),
    );
});

afterEach(function () use (&$redisFixtureCache): void {
    $redisFixtureCache->clear();
});

/* ── 1. convenience set()/get() ───────────────────────────────────── */
test('Redis set()/get()', function () use (&$redisFixtureCache): void {
    expect($redisFixtureCache->get('none'))->toBeNull()
        ->and($redisFixtureCache->set('foo', 'bar'))->toBeTrue()
        ->and($redisFixtureCache->get('foo'))->toBe('bar');
});

/* ─── PSR-16 get($key, $default) ───────────────────────────────── */
test('get returns default when key missing (redis)', function () use (&$redisFixtureCache): void {
    expect($redisFixtureCache->get('nobody', 'dflt'))->toBe('dflt');

    $default = static fn(): string => 'xyz';
    expect($redisFixtureCache->get('dynamic', $default))->toBe($default)
        ->and($redisFixtureCache->has('dynamic'))->toBeFalse();
});

test('get throws for invalid key (redis)', function () use (&$redisFixtureCache): void {
    expect(fn (): mixed => $redisFixtureCache->get('bad key', 'v'))
        ->toThrow(CacheInvalidArgumentException::class);
});

/* ── 2. PSR-6 behaviour ─────────────────────────────────────────── */
test('getItem()/save() (redis)', function () use (&$redisFixtureCache): void {
    $it = $redisFixtureCache->getItem('psr');
    assert($it instanceof CacheItem);
    expect($it)->toBeInstanceOf(CacheItem::class)
        ->and($it->isHit())->toBeFalse();

    $it->set(777)->save();
    expect($redisFixtureCache->getItem('psr')->get())->toBe(777);
});

/* ── 3. deferred queue ──────────────────────────────────────────── */
test('saveDeferred() & commit() (redis)', function () use (&$redisFixtureCache): void {
    \Infocyph\CacheLayer\Tests\Support\CacheFixture::item($redisFixtureCache, 'a')->set('A')->saveDeferred();
    expect($redisFixtureCache->get('a'))->toBe('A');

    $redisFixtureCache->commit();
    expect($redisFixtureCache->get('a'))->toBe('A');
});

/* ── 4. ArrayAccess ─────────────────────────────────────────────── */
test('ArrayAccess (redis)', function () use (&$redisFixtureCache): void {
    $redisFixtureCache['k'] = 12;
    expect($redisFixtureCache['k'])->toBe(12)
        ->and(method_exists($redisFixtureCache, '__get'))->toBeFalse();
});

/* ── 6. TTL expiration ─────────────────────────────────────────── */
test('expiration honours TTL (redis)', function () use (&$redisFixtureCache): void {
    \Infocyph\CacheLayer\Tests\Support\CacheFixture::item($redisFixtureCache, 'ttl')->set('x')->expiresAfter(1)->save();
    usleep(2_000_000);
    expect($redisFixtureCache->hasItem('ttl'))->toBeFalse();
});

/* ── 7. closure round-trip ──────────────────────────────────────── */
test('closure persists in redis', function () use (&$redisFixtureCache): void {
    $double = fn (int $n): int => $n * 2;
    \Infocyph\CacheLayer\Tests\Support\CacheFixture::item($redisFixtureCache, 'cb')->set($double)->save();
    $fn = $redisFixtureCache->getItem('cb')->get();
    assert($fn instanceof Closure);
    expect($fn(5))->toBe(10);
});

/* ── 9. invalid key guard ───────────────────────────────────────── */
test('invalid key throws (redis)', function () use (&$redisFixtureCache): void {
    expect(fn () => $redisFixtureCache->set('bad key', 'v'))
        ->toThrow(InvalidArgumentException::class);
});

/* ── 10. clear wipes namespace ----------------------------------- */
test('clear() wipes entries (redis)', function () use (&$redisFixtureCache): void {
    $redisFixtureCache->set('z', 9);
    $redisFixtureCache->clear();
    expect($redisFixtureCache->hasItem('z'))->toBeFalse();
});

test('Redis adapter multiFetch()', function () use (&$redisFixtureCache): void {
    $redisFixtureCache->set('r1', 10);
    $redisFixtureCache->set('r2', 20);

    $items = iterator_to_array($redisFixtureCache->getItems(['r1', 'r2', 'none']));

    expect($items['r1']->get())->toBe(10)
        ->and($items['r2']->get())->toBe(20)
        ->and($items['none']->isHit())->toBeFalse();
});

test('Redis exposes native atomic cache capability', function () use (&$redisFixtureCache): void {
    expect($redisFixtureCache->atomic())->toBeInstanceOf(AtomicCacheInterface::class);
});

test('Redis setIfAbsent is conditional and TTL-aware', function () use (&$redisFixtureCache): void {
    $atomic = $redisFixtureCache->atomic() ?? throw new \RuntimeException('The test requires atomic cache capability.');

    expect($atomic->setIfAbsent('claim', 'first', 1))->toBeTrue()
        ->and($atomic->setIfAbsent('claim', 'second', 30))->toBeFalse()
        ->and($redisFixtureCache->get('claim'))->toBe('first');

    usleep(2_000_000);

    expect($atomic->setIfAbsent('claim', 'after-expiry', 30))->toBeTrue()
        ->and($redisFixtureCache->get('claim'))->toBe('after-expiry');
});

test('Redis setIfAbsent can replace a stale tagged physical record', function () use (&$redisFixtureCache): void {
    $atomic = $redisFixtureCache->atomic() ?? throw new \RuntimeException('The test requires atomic cache capability.');
    $redisFixtureCache->setTagged('claim', 'stale', ['group']);
    $redisFixtureCache->invalidateTag('group');

    expect($atomic->setIfAbsent('claim', 'fresh', 30))->toBeTrue()
        ->and($redisFixtureCache->get('claim'))->toBe('fresh');
});

test('Redis compareAndSet strictly replaces one live untagged value', function () use (&$redisFixtureCache): void {
    $atomic = $redisFixtureCache->atomic() ?? throw new \RuntimeException('The test requires atomic cache capability.');
    $redisFixtureCache->set('version', 1, 30);

    expect($atomic->compareAndSet('version', '1', 2, 30))->toBeFalse()
        ->and($atomic->compareAndSet('version', 1, 2, 30))->toBeTrue()
        ->and($redisFixtureCache->get('version'))->toBe(2)
        ->and($atomic->compareAndSet('version', 1, 3, 30))->toBeFalse();
});

test('Redis compareAndSet does not claim atomic tag coordination', function () use (&$redisFixtureCache): void {
    $atomic = $redisFixtureCache->atomic() ?? throw new \RuntimeException('The test requires atomic cache capability.');
    $redisFixtureCache->setTagged('tagged', 'old', ['group'], 30);

    expect($atomic->compareAndSet('tagged', 'old', 'new', 30))->toBeFalse()
        ->and($redisFixtureCache->get('tagged'))->toBe('old');
});

test('Redis getAndDelete consumes one value atomically', function () use (&$redisFixtureCache): void {
    $atomic = $redisFixtureCache->atomic() ?? throw new \RuntimeException('The test requires atomic cache capability.');
    $redisFixtureCache->set('one-time', null, 30);

    expect($atomic->getAndDelete('one-time', 'missing'))->toBeNull()
        ->and($atomic->getAndDelete('one-time', 'missing'))->toBe('missing')
        ->and($redisFixtureCache->has('one-time'))->toBeFalse();
});

test('Redis atomic claims have one winner under process contention', function () use ($redisHost, $redisPort, $redisPassword, $finishForkedTest, &$redisFixtureCache): void {
    $atomic = $redisFixtureCache->atomic() ?? throw new \RuntimeException('The test requires atomic cache capability.');

    if (!function_exists('pcntl_fork')) {
        $wins = 0;
        for ($attempt = 0; $attempt < 16; ++$attempt) {
            $wins += $atomic->setIfAbsent('contended', (string) $attempt, 30) ? 1 : 0;
        }

        expect($wins)->toBe(1);

        return;
    }

    $children = [];
    for ($worker = 0; $worker < 8; ++$worker) {
        $pid = pcntl_fork();
        if ($pid === 0) {
            $client = new Redis;
            $client->connect($redisHost, $redisPort);
            if ($redisPassword !== '') {
                $client->auth($redisPassword);
            }
            $cache = Cache::redis('tests', sprintf('redis://%s:%d', $redisHost, $redisPort), $client);
            $finishForkedTest($cache->atomic()?->setIfAbsent('contended', (string) $worker, 30) === true);
        }
        if ($pid < 0) {
            throw new RuntimeException('Unable to fork a cache contention process.');
        }
        $children[] = $pid;
    }

    $wins = 0;
    foreach ($children as $pid) {
        if (pcntl_waitpid($pid, $status) !== $pid || !is_int($status) || !pcntl_wifexited($status)) {
            throw new RuntimeException('Redis contention process did not exit normally.');
        }
        $wins += pcntl_wexitstatus($status) === 0 ? 1 : 0;
    }

    expect($wins)->toBe(1);
});

test('Redis atomic compare and set has one winner under process contention', function () use ($redisHost, $redisPort, $redisPassword, $finishForkedTest, &$redisFixtureCache): void {
    $atomic = $redisFixtureCache->atomic() ?? throw new \RuntimeException('The test requires atomic cache capability.');
    $redisFixtureCache->set('cas-contended', 0, 30);

    if (!function_exists('pcntl_fork')) {
        $wins = 0;
        for ($attempt = 1; $attempt <= 16; ++$attempt) {
            $wins += $atomic->compareAndSet('cas-contended', 0, $attempt, 30) ? 1 : 0;
        }

        expect($wins)->toBe(1);

        return;
    }

    $children = [];
    for ($worker = 1; $worker <= 8; ++$worker) {
        $pid = pcntl_fork();
        if ($pid === 0) {
            $client = new Redis;
            $client->connect($redisHost, $redisPort);
            if ($redisPassword !== '') {
                $client->auth($redisPassword);
            }
            $cache = Cache::redis('tests', sprintf('redis://%s:%d', $redisHost, $redisPort), $client);
            $finishForkedTest($cache->atomic()?->compareAndSet('cas-contended', 0, $worker, 30) === true);
        }
        if ($pid < 0) {
            throw new RuntimeException('Unable to fork a cache contention process.');
        }
        $children[] = $pid;
    }

    $wins = 0;
    foreach ($children as $pid) {
        if (pcntl_waitpid($pid, $status) !== $pid || !is_int($status) || !pcntl_wifexited($status)) {
            throw new RuntimeException('Redis contention process did not exit normally.');
        }
        $wins += pcntl_wexitstatus($status) === 0 ? 1 : 0;
    }

    expect($wins)->toBe(1)
        ->and($redisFixtureCache->get('cas-contended'))->toBeGreaterThanOrEqual(1)
        ->and($redisFixtureCache->get('cas-contended'))->toBeLessThanOrEqual(8);
});

test('Redis atomic consumption has one winner under process contention', function () use ($redisHost, $redisPort, $redisPassword, $finishForkedTest, &$redisFixtureCache): void {
    $atomic = $redisFixtureCache->atomic() ?? throw new \RuntimeException('The test requires atomic cache capability.');
    $redisFixtureCache->set('consume-once', 'payload', 30);

    if (!function_exists('pcntl_fork')) {
        $wins = 0;
        for ($attempt = 0; $attempt < 16; ++$attempt) {
            $wins += $atomic->getAndDelete('consume-once', '__missing__') === 'payload' ? 1 : 0;
        }

        expect($wins)->toBe(1);

        return;
    }

    $children = [];
    for ($worker = 0; $worker < 8; ++$worker) {
        $pid = pcntl_fork();
        if ($pid === 0) {
            $client = new Redis;
            $client->connect($redisHost, $redisPort);
            if ($redisPassword !== '') {
                $client->auth($redisPassword);
            }
            $cache = Cache::redis('tests', sprintf('redis://%s:%d', $redisHost, $redisPort), $client);
            $finishForkedTest($cache->atomic()?->getAndDelete('consume-once', '__missing__') === 'payload');
        }
        if ($pid < 0) {
            throw new RuntimeException('Unable to fork a cache contention process.');
        }
        $children[] = $pid;
    }

    $wins = 0;
    foreach ($children as $pid) {
        if (pcntl_waitpid($pid, $status) !== $pid || !is_int($status) || !pcntl_wifexited($status)) {
            throw new RuntimeException('Redis contention process did not exit normally.');
        }
        $wins += pcntl_wexitstatus($status) === 0 ? 1 : 0;
    }

    expect($wins)->toBe(1);
});


test('Redis cache clear does not reset isolated atomic counters and large integers stay exact', function () use (&$redisFixtureCache, &$redisFixtureRedisClient): void {
    $counters = AtomicCounters::redis('tests', client: $redisFixtureRedisClient);
    $large = 9_007_199_254_740_993;

    expect($counters->increment('large', $large)->value)->toBe($large)
        ->and($redisFixtureCache->set('ordinary', 'value'))->toBeTrue()
        ->and($redisFixtureCache->clear())->toBeTrue()
        ->and($counters->get('large'))->toBe($large);
});

test('Redis atomic counters preserve TTL, decrement, overflow, and invalid-value contracts', function () use (&$redisFixtureRedisClient): void {
    $counters = AtomicCounters::redis('tests', client: $redisFixtureRedisClient);
    $first = $counters->increment('window', 5, 30);
    $physical = 'cachelayer:counter:tests:window';
    $ttlBefore = $redisFixtureRedisClient->ttl($physical);
    $later = $counters->decrement('window', 2, 30);
    $ttlAfter = $redisFixtureRedisClient->ttl($physical);

    expect($first->initialized)->toBeTrue()
        ->and($later->initialized)->toBeFalse()
        ->and($later->value)->toBe(3)
        ->and($ttlBefore)->toBeGreaterThan(0)
        ->and($ttlAfter)->toBeGreaterThan(0)
        ->and($ttlAfter)->toBeLessThanOrEqual($ttlBefore);

    $redisFixtureRedisClient->set('cachelayer:counter:tests:max', (string) PHP_INT_MAX);
    expect($counters->get('max'))->toBe(PHP_INT_MAX)
        ->and(fn () => $counters->increment('max'))->toThrow(AtomicCounterException::class);

    $redisFixtureRedisClient->set('cachelayer:counter:tests:out-of-range', '9223372036854775808');
    $redisFixtureRedisClient->set('cachelayer:counter:tests:malformed', '12x');
    expect(fn () => $counters->get('out-of-range'))->toThrow(AtomicCounterException::class)
        ->and(fn () => $counters->get('malformed'))->toThrow(AtomicCounterException::class);
});

test('Redis atomic counter initialization has exactly one winner under contention', function () use ($redisHost, $redisPort, $redisPassword, &$redisFixtureRedisClient): void {
    $counters = AtomicCounters::redis('tests', client: $redisFixtureRedisClient);
    $wins = AtomicCounterProcessProbe::initializedWinners(
        'redis',
        $redisHost,
        $redisPort,
        $redisPassword,
        'tests',
        'contended-counter',
    );

    expect($wins)->toBe(1)
        ->and($counters->get('contended-counter'))->toBe(8);
});

test('Redis stale cleanup never deletes or overwrites a concurrent replacement', function () use (&$redisFixtureRedisClient): void {
    $key = 'cachelayer:guard:race';

    $redisFixtureRedisClient->set($key, 'fresh');
    expect(RedisValueGuard::deleteIfUnchanged($redisFixtureRedisClient, $key, 'stale'))->toBeFalse()
        ->and($redisFixtureRedisClient->get($key))->toBe('fresh')
        ->and(RedisValueGuard::replaceIfUnchanged($redisFixtureRedisClient, $key, 'stale', 'repair'))->toBe('fresh')
        ->and($redisFixtureRedisClient->get($key))->toBe('fresh');

    $redisFixtureRedisClient->set($key, 'stale');
    expect(RedisValueGuard::replaceIfUnchanged($redisFixtureRedisClient, $key, 'stale', 'repair'))->toBe('repair')
        ->and($redisFixtureRedisClient->get($key))->toBe('repair')
        ->and(RedisValueGuard::deleteIfUnchanged($redisFixtureRedisClient, $key, 'repair'))->toBeTrue()
        ->and($redisFixtureRedisClient->get($key))->toBeFalse();
});


test('Redis atomic counters expire fixed windows', function () use (&$redisFixtureRedisClient): void {
    $counters = AtomicCounters::redis('tests', client: $redisFixtureRedisClient);

    expect($counters->increment('short-window', 1, 1)->initialized)->toBeTrue()
        ->and($counters->get('short-window'))->toBe(1);
    usleep(2_000_000);

    expect($counters->get('short-window'))->toBeNull();
});


test('Redis atomic consume discards deferred overlays without resurrection', function () use (&$redisFixtureCache): void {
    $atomic = $redisFixtureCache->atomic() ?? throw new \RuntimeException('The test requires atomic cache capability.');

    expect($redisFixtureCache->set('deferred-consume', 'stored'))->toBeTrue()
        ->and($redisFixtureCache->saveDeferred($redisFixtureCache->getItem('deferred-consume')->set('pending')))->toBeTrue()
        ->and($atomic->getAndDelete('deferred-consume', 'missing'))->toBe('stored')
        ->and($atomic->getAndDelete('deferred-consume', 'missing'))->toBe('missing')
        ->and($redisFixtureCache->commit())->toBeTrue()
        ->and($redisFixtureCache->get('deferred-consume'))->toBeNull();
});


test('Redis clear in boundary namespace preserves counters locks and invalidation streams', function () use (&$redisFixtureRedisClient): void {
    $cache = Cache::redis('cachelayer', client: $redisFixtureRedisClient);
    $counters = AtomicCounters::redis('audit-counter', client: $redisFixtureRedisClient);
    $locks = new \Infocyph\CacheLayer\Cache\Lock\RedisLockProvider($redisFixtureRedisClient);
    $transport = new \Infocyph\CacheLayer\Cluster\Transport\RedisStreamInvalidationTransport($redisFixtureRedisClient);

    expect($cache->set('ordinary', 'value'))->toBeTrue()
        ->and($counters->increment('window', 5, 30)->value)->toBe(5);

    $held = $locks->acquire('boundary-lock', 0.0, 30.0);
    expect($held)->not->toBeNull();

    $eventId = $transport->publish(
        \Infocyph\CacheLayer\Cluster\Event\InvalidationEvent::key(
            'clear-boundary',
            'application',
            'product.42',
            'writer',
        ),
    );
    expect($eventId)->not->toBe('');

    expect($cache->clear())->toBeTrue()
        ->and($cache->get('ordinary'))->toBeNull()
        ->and($counters->get('window'))->toBe(5)
        ->and($locks->acquire('boundary-lock', 0.0, 30.0))->toBeNull()
        ->and($redisFixtureRedisClient->xLen('cachelayer:invalidation:clear-boundary'))->toBe(1);

    $locks->release($held);
});

test('Redis compare-safe cleanup preserves a concurrent replacement', function () use (&$redisFixtureRedisClient): void {
    $key = 'cachelayer:guard:stale-read';
    $redisFixtureRedisClient->set($key, 'observed-invalid');
    $observed = $redisFixtureRedisClient->get($key);
    if (!is_string($observed)) {
        throw new RuntimeException('Redis did not return the seeded string value.');
    }
    expect($observed)->toBe('observed-invalid');

    $redisFixtureRedisClient->set($key, 'replacement');

    expect(RedisValueGuard::deleteIfUnchanged($redisFixtureRedisClient, $key, $observed))->toBeFalse()
        ->and($redisFixtureRedisClient->get($key))->toBe('replacement');
});

test('tag-stale Redis reads return misses without physically deleting an observed record', function () use (&$redisFixtureCache, &$redisFixtureRedisClient): void {
    expect($redisFixtureCache->setTagged('tagged-stale', 'old', ['products'], 30))->toBeTrue();
    $physical = 'tests:d:tagged-stale';
    expect($redisFixtureRedisClient->exists($physical))->toBe(1);

    expect($redisFixtureCache->invalidateTag('products'))->toBeTrue()
        ->and($redisFixtureCache->get('tagged-stale'))->toBeNull()
        ->and($redisFixtureRedisClient->exists($physical))->toBe(1)
        ->and($redisFixtureCache->setTagged('tagged-stale', 'fresh', ['products'], 30))->toBeTrue()
        ->and($redisFixtureCache->get('tagged-stale'))->toBe('fresh');
});


test('Redis Stream recovery clears stale local state after complete history loss', function () use (&$redisFixtureRedisClient): void {
    $directory = sys_get_temp_dir() . '/cachelayer-redis-history-' . uniqid('', true);
    mkdir($directory, 0700, true);

    try {
        $transport = new \Infocyph\CacheLayer\Cluster\Transport\RedisStreamInvalidationTransport(
            $redisFixtureRedisClient,
            'cachelayer:history:',
        );
        $runtime = \Infocyph\CacheLayer\Cluster\ClusterCache::create(
            new \Infocyph\CacheLayer\Node\NodeCacheConfig(
                $directory . '/node.sqlite',
                'application',
                apcuEnabled: false,
            ),
            new \Infocyph\CacheLayer\Cluster\ClusterCacheConfig(
                'redis-history-loss',
                'consumer',
                'redis-history-loss',
            ),
            $transport,
        );

        $transport->publish(
            \Infocyph\CacheLayer\Cluster\Event\InvalidationEvent::key(
                'redis-history-loss',
                'application',
                'first',
                'writer',
            ),
        );
        expect($runtime->consume())->toBe(1);

        $runtime->cache()->set('stale', 'value', 300);
        $transport->publish(
            \Infocyph\CacheLayer\Cluster\Event\InvalidationEvent::key(
                'redis-history-loss',
                'application',
                'stale',
                'writer',
            ),
        );
        $redisFixtureRedisClient->del('cachelayer:history:redis-history-loss');

        expect($runtime->recoverIfRequired())->toBeTrue()
            ->and($runtime->cache()->get('stale'))->toBeNull()
            ->and($runtime->status()->cursor)->toBeNull()
            ->and($runtime->recoverIfRequired())->toBeFalse();
    } finally {
        if (is_dir($directory)) {
            $files = glob($directory . '/*');
            if ($files === false) {
                throw new RuntimeException('Unable to enumerate cache test files.');
            }
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($directory);
        }
    }
});


test('Redis authentication failures do not expose supplied passwords in exception traces', function () use ($redisHost, $redisPort) {
    $secret = 'AUDIT_SENTINEL_40';
    $previousIgnoreArgs = ini_get('zend.exception_ignore_args');
    $previousMaxLen = ini_get('zend.exception_string_param_max_len');
    ini_set('zend.exception_ignore_args', '0');
    ini_set('zend.exception_string_param_max_len', '128');

    try {
        try {
            \Infocyph\CacheLayer\Support\RedisConnection::connect(
                sprintf('redis://:%s@%s:%d', rawurlencode($secret), $redisHost, $redisPort),
            );
        } catch (Throwable $failure) {
            expect((string) $failure)->not->toContain($secret);

            return;
        }
        \PHPUnit\Framework\Assert::fail('Expected Redis authentication with the synthetic secret to fail.');
    } finally {
        if (is_string($previousIgnoreArgs)) {
            ini_set('zend.exception_ignore_args', $previousIgnoreArgs);
        }
        if (is_string($previousMaxLen)) {
            ini_set('zend.exception_string_param_max_len', $previousMaxLen);
        }
    }
});

test('Redis clear and delete propagate a refused native delete operation', function (): void {
    $client = $this->createMock(Redis::class);
    $client->method('scan')->willReturnCallback(static function (mixed &$cursor): array {
        $cursor = 0;

        return ['refused:d:key'];
    });
    $client->method('del')->willReturn(false);
    $adapter = new \Infocyph\CacheLayer\Cache\Adapter\RedisCacheAdapter('refused', client: $client);

    expect($adapter->deleteItem('key'))->toBeFalse()
        ->and($adapter->deleteItems(['key']))->toBeFalse()
        ->and($adapter->clear())->toBeFalse();
});

test('Redis incomplete bulk responses are backend failures rather than ordinary misses', function (): void {
    $client = $this->createMock(Redis::class);
    $client->method('mget')->willReturn([false]);
    $adapter = new \Infocyph\CacheLayer\Cache\Adapter\RedisCacheAdapter('incomplete', client: $client);
    $closed = new Cache($adapter, options: new CacheOptions(failOpen: false));
    expect(fn (): iterable => $closed->getMultiple(['one', 'two']))
        ->toThrow(\Infocyph\CacheLayer\Exceptions\CacheBackendException::class);
    expect(fn (): array => $adapter->getTagGenerations(['one', 'two']))
        ->toThrow(RuntimeException::class);

    $open = new Cache(new \Infocyph\CacheLayer\Cache\Adapter\RedisCacheAdapter('incomplete-open', client: $client), options: new CacheOptions(failOpen: true));
    expect($open->getMultiple(['one', 'two'], 'fallback'))->toBe(['one' => 'fallback', 'two' => 'fallback']);
});

test('Redis bulk persistence does not report success when expired entry deletion fails', function (): void {
    $client = $this->createMock(Redis::class);
    $client->method('del')->willReturn(false);
    $adapter = new \Infocyph\CacheLayer\Cache\Adapter\RedisCacheAdapter('expired-batch', client: $client);
    $item = $adapter->createItem('expired')->set('old')->expiresAfter(-1);
    expect($adapter->saveItems(['expired' => $item]))->toBeFalse();
});

test('Redis counter deletion preserves missing-key success and reports backend refusal', function () use (&$redisFixtureRedisClient): void {
    $counters = AtomicCounters::redis('delete-contract', client: $redisFixtureRedisClient);
    $counters->increment('counter');
    expect($counters->delete('counter'))->toBeTrue()
        ->and($counters->get('counter'))->toBeNull()
        ->and($counters->delete('counter'))->toBeTrue();

    $client = $this->createMock(Redis::class);
    $client->method('del')->willReturn(false);
    expect(AtomicCounters::redis('delete-contract', client: $client)->delete('counter'))->toBeFalse();
});
