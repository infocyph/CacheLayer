<?php

declare(strict_types=1);

/**
 * tests/RedisCachePoolTest.php
 *
 * Executes the same behavioural checks as the File/APCu/Memcache/SQLite
 * suites, but against the Redis adapter.  The suite self-skips when:
 *   • phpredis extension is not loaded, or
 *   • no Redis server answers at 127.0.0.1:6379.
 */

use Infocyph\CacheLayer\Cache\AtomicCacheInterface;
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

$redisHost = getenv('IC_REDIS_HOST') ?: getenv('CACHELAYER_REDIS_HOST') ?: '127.0.0.1';
$redisPort = (int) (getenv('IC_REDIS_PORT') ?: getenv('CACHELAYER_REDIS_PORT') ?: '6379');
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
beforeEach(function () use ($redisHost, $redisPort, $redisPassword) {
    $client = new Redis;
    $client->connect($redisHost, $redisPort);
    if ($redisPassword !== '') {
        $client->auth($redisPassword);
    }
    $client->flushDB();                               // fresh DB 0

    $this->redisClient = $client;
    $this->cache = Cache::redis(
        'tests',
        sprintf('redis://%s:%d', $redisHost, $redisPort),
        $client,
        new CacheOptions(allowClosures: true),
    );
});

afterEach(function () {
    $this->cache->clear();
});

/* ── 1. convenience set()/get() ───────────────────────────────────── */
test('Redis set()/get()', function () {
    expect($this->cache->get('none'))->toBeNull()
        ->and($this->cache->set('foo', 'bar'))->toBeTrue()
        ->and($this->cache->get('foo'))->toBe('bar');
});

/* ─── PSR-16 get($key, $default) ───────────────────────────────── */
test('get returns default when key missing (redis)', function () {
    expect($this->cache->get('nobody', 'dflt'))->toBe('dflt');

    $default = static fn(): string => 'xyz';
    expect($this->cache->get('dynamic', $default))->toBe($default)
        ->and($this->cache->has('dynamic'))->toBeFalse();
});

test('get throws for invalid key (redis)', function () {
    expect(fn () => $this->cache->get('bad key', 'v'))
        ->toThrow(CacheInvalidArgumentException::class);
});

/* ── 2. PSR-6 behaviour ─────────────────────────────────────────── */
test('getItem()/save() (redis)', function () {
    $it = $this->cache->getItem('psr');
    expect($it)->toBeInstanceOf(CacheItem::class)
        ->and($it->isHit())->toBeFalse();

    $it->set(777)->save();
    expect($this->cache->getItem('psr')->get())->toBe(777);
});

/* ── 3. deferred queue ──────────────────────────────────────────── */
test('saveDeferred() & commit() (redis)', function () {
    $this->cache->getItem('a')->set('A')->saveDeferred();
    expect($this->cache->get('a'))->toBe('A');

    $this->cache->commit();
    expect($this->cache->get('a'))->toBe('A');
});

/* ── 4. ArrayAccess ─────────────────────────────────────────────── */
test('ArrayAccess (redis)', function () {
    $this->cache['k'] = 12;
    expect($this->cache['k'])->toBe(12)
        ->and(method_exists($this->cache, '__get'))->toBeFalse();
});

/* ── 6. TTL expiration ─────────────────────────────────────────── */
test('expiration honours TTL (redis)', function () {
    $this->cache->getItem('ttl')->set('x')->expiresAfter(1)->save();
    usleep(2_000_000);
    expect($this->cache->hasItem('ttl'))->toBeFalse();
});

/* ── 7. closure round-trip ──────────────────────────────────────── */
test('closure persists in redis', function () {
    $double = fn ($n) => $n * 2;
    $this->cache->getItem('cb')->set($double)->save();
    $fn = $this->cache->getItem('cb')->get();
    expect($fn(5))->toBe(10);
});

/* ── 9. invalid key guard ───────────────────────────────────────── */
test('invalid key throws (redis)', function () {
    expect(fn () => $this->cache->set('bad key', 'v'))
        ->toThrow(InvalidArgumentException::class);
});

/* ── 10. clear wipes namespace ----------------------------------- */
test('clear() wipes entries (redis)', function () {
    $this->cache->set('z', 9);
    $this->cache->clear();
    expect($this->cache->hasItem('z'))->toBeFalse();
});

test('Redis adapter multiFetch()', function () {
    $this->cache->set('r1', 10);
    $this->cache->set('r2', 20);

    $items = $this->cache->getItems(['r1', 'r2', 'none']);

    expect($items['r1']->get())->toBe(10)
        ->and($items['r2']->get())->toBe(20)
        ->and($items['none']->isHit())->toBeFalse();
});

test('Redis exposes native atomic cache capability', function () {
    expect($this->cache->atomic())->toBeInstanceOf(AtomicCacheInterface::class);
});

test('Redis setIfAbsent is conditional and TTL-aware', function () {
    $atomic = $this->cache->atomic();

    expect($atomic)->not->toBeNull()
        ->and($atomic->setIfAbsent('claim', 'first', 1))->toBeTrue()
        ->and($atomic->setIfAbsent('claim', 'second', 30))->toBeFalse()
        ->and($this->cache->get('claim'))->toBe('first');

    usleep(2_000_000);

    expect($atomic->setIfAbsent('claim', 'after-expiry', 30))->toBeTrue()
        ->and($this->cache->get('claim'))->toBe('after-expiry');
});

test('Redis setIfAbsent can replace a stale tagged physical record', function () {
    $atomic = $this->cache->atomic();

    expect($atomic)->not->toBeNull();
    $this->cache->setTagged('claim', 'stale', ['group']);
    $this->cache->invalidateTag('group');

    expect($atomic->setIfAbsent('claim', 'fresh', 30))->toBeTrue()
        ->and($this->cache->get('claim'))->toBe('fresh');
});

test('Redis compareAndSet strictly replaces one live untagged value', function () {
    $atomic = $this->cache->atomic();

    expect($atomic)->not->toBeNull();
    $this->cache->set('version', 1, 30);

    expect($atomic->compareAndSet('version', '1', 2, 30))->toBeFalse()
        ->and($atomic->compareAndSet('version', 1, 2, 30))->toBeTrue()
        ->and($this->cache->get('version'))->toBe(2)
        ->and($atomic->compareAndSet('version', 1, 3, 30))->toBeFalse();
});

test('Redis compareAndSet does not claim atomic tag coordination', function () {
    $atomic = $this->cache->atomic();

    expect($atomic)->not->toBeNull();
    $this->cache->setTagged('tagged', 'old', ['group'], 30);

    expect($atomic->compareAndSet('tagged', 'old', 'new', 30))->toBeFalse()
        ->and($this->cache->get('tagged'))->toBe('old');
});

test('Redis getAndDelete consumes one value atomically', function () {
    $atomic = $this->cache->atomic();

    expect($atomic)->not->toBeNull();
    $this->cache->set('one-time', null, 30);

    expect($atomic->getAndDelete('one-time', 'missing'))->toBeNull()
        ->and($atomic->getAndDelete('one-time', 'missing'))->toBe('missing')
        ->and($this->cache->has('one-time'))->toBeFalse();
});

test('Redis atomic claims have one winner under process contention', function () use ($redisHost, $redisPort, $redisPassword, $finishForkedTest) {
    $atomic = $this->cache->atomic();
    expect($atomic)->not->toBeNull();

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
        if ($pid > 0) {
            $children[] = $pid;
        }
    }

    $wins = 0;
    foreach ($children as $pid) {
        pcntl_waitpid($pid, $status);
        $wins += pcntl_wexitstatus($status) === 0 ? 1 : 0;
    }

    expect($wins)->toBe(1);
});

test('Redis atomic compare and set has one winner under process contention', function () use ($redisHost, $redisPort, $redisPassword, $finishForkedTest) {
    $atomic = $this->cache->atomic();
    expect($atomic)->not->toBeNull();
    $this->cache->set('cas-contended', 0, 30);

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
        if ($pid > 0) {
            $children[] = $pid;
        }
    }

    $wins = 0;
    foreach ($children as $pid) {
        pcntl_waitpid($pid, $status);
        $wins += pcntl_wexitstatus($status) === 0 ? 1 : 0;
    }

    expect($wins)->toBe(1)
        ->and($this->cache->get('cas-contended'))->toBeGreaterThanOrEqual(1)
        ->and($this->cache->get('cas-contended'))->toBeLessThanOrEqual(8);
});

test('Redis atomic consumption has one winner under process contention', function () use ($redisHost, $redisPort, $redisPassword, $finishForkedTest) {
    $atomic = $this->cache->atomic();
    expect($atomic)->not->toBeNull();
    $this->cache->set('consume-once', 'payload', 30);

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
        if ($pid > 0) {
            $children[] = $pid;
        }
    }

    $wins = 0;
    foreach ($children as $pid) {
        pcntl_waitpid($pid, $status);
        $wins += pcntl_wexitstatus($status) === 0 ? 1 : 0;
    }

    expect($wins)->toBe(1);
});


test('Redis cache clear does not reset isolated atomic counters and large integers stay exact', function () {
    $counters = AtomicCounters::redis('tests', client: $this->redisClient);
    $large = 9_007_199_254_740_993;

    expect($counters->increment('large', $large))
        ->value->toBe($large)
        ->and($this->cache->set('ordinary', 'value'))->toBeTrue()
        ->and($this->cache->clear())->toBeTrue()
        ->and($counters->get('large'))->toBe($large);
});

test('Redis atomic counters preserve TTL, decrement, overflow, and invalid-value contracts', function () {
    $counters = AtomicCounters::redis('tests', client: $this->redisClient);
    $first = $counters->increment('window', 5, 30);
    $physical = 'cachelayer:counter:tests:window';
    $ttlBefore = $this->redisClient->ttl($physical);
    $later = $counters->decrement('window', 2, 30);
    $ttlAfter = $this->redisClient->ttl($physical);

    expect($first->initialized)->toBeTrue()
        ->and($later->initialized)->toBeFalse()
        ->and($later->value)->toBe(3)
        ->and($ttlBefore)->toBeGreaterThan(0)
        ->and($ttlAfter)->toBeGreaterThan(0)
        ->and($ttlAfter)->toBeLessThanOrEqual($ttlBefore);

    $this->redisClient->set('cachelayer:counter:tests:max', (string) PHP_INT_MAX);
    expect($counters->get('max'))->toBe(PHP_INT_MAX)
        ->and(fn () => $counters->increment('max'))->toThrow(AtomicCounterException::class);

    $this->redisClient->set('cachelayer:counter:tests:out-of-range', '9223372036854775808');
    $this->redisClient->set('cachelayer:counter:tests:malformed', '12x');
    expect(fn () => $counters->get('out-of-range'))->toThrow(AtomicCounterException::class)
        ->and(fn () => $counters->get('malformed'))->toThrow(AtomicCounterException::class);
});

test('Redis atomic counter initialization has exactly one winner under contention', function () use ($redisHost, $redisPort, $redisPassword) {
    $counters = AtomicCounters::redis('tests', client: $this->redisClient);
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

test('Redis stale cleanup never deletes or overwrites a concurrent replacement', function () {
    $key = 'cachelayer:guard:race';

    $this->redisClient->set($key, 'fresh');
    expect(RedisValueGuard::deleteIfUnchanged($this->redisClient, $key, 'stale'))->toBeFalse()
        ->and($this->redisClient->get($key))->toBe('fresh')
        ->and(RedisValueGuard::replaceIfUnchanged($this->redisClient, $key, 'stale', 'repair'))->toBe('fresh')
        ->and($this->redisClient->get($key))->toBe('fresh');

    $this->redisClient->set($key, 'stale');
    expect(RedisValueGuard::replaceIfUnchanged($this->redisClient, $key, 'stale', 'repair'))->toBe('repair')
        ->and($this->redisClient->get($key))->toBe('repair')
        ->and(RedisValueGuard::deleteIfUnchanged($this->redisClient, $key, 'repair'))->toBeTrue()
        ->and($this->redisClient->get($key))->toBeFalse();
});


test('Redis atomic counters expire fixed windows', function () {
    $counters = AtomicCounters::redis('tests', client: $this->redisClient);

    expect($counters->increment('short-window', 1, 1)->initialized)->toBeTrue()
        ->and($counters->get('short-window'))->toBe(1);
    usleep(2_000_000);

    expect($counters->get('short-window'))->toBeNull();
});
