<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cache\Adapter\SharedMemoryCacheAdapter;
use Infocyph\CacheLayer\Cache\AtomicCacheInterface;
use Infocyph\CacheLayer\Cache\Cache;

if (! function_exists('shm_attach')) {
    test('shared memory extension not loaded')->skip();

    return;
}

test('shared memory adapter shares values across instances', function () {
    $a = Cache::sharedMemory('shm-tests');
    $b = Cache::sharedMemory('shm-tests');

    $a->set('k', 'value');

    expect($b->get('k'))->toBe('value');

    $a->clear();
});

test('shared memory adapter uses private filesystem and segment permissions', function () {
    $adapter = new SharedMemoryCacheAdapter('shm-security-tests');
    $reflection = new ReflectionClass($adapter);
    $tokenFile = $reflection->getProperty('tokenFile')->getValue($adapter);

    expect($tokenFile)->toBeString()
        ->and(is_file($tokenFile))->toBeTrue()
        ->and(fileperms($tokenFile) & 0x0002)->toBe(0);

    $adapter->clear();
});

test('shared memory namespace clear cannot affect another namespace', function () {
    $first = Cache::sharedMemory('shm-isolation-a');
    $second = Cache::sharedMemory('shm-isolation-b');

    $first->set('key', 'first');
    $second->set('key', 'second');
    $first->clear();

    expect($first->get('key'))->toBeNull()
        ->and($second->get('key'))->toBe('second');

    $second->clear();
});

test('shared memory exposes atomic capability across instances', function () {
    $first = Cache::sharedMemory('shm-atomic');
    $second = Cache::sharedMemory('shm-atomic');
    $firstAtomic = $first->atomic();
    $secondAtomic = $second->atomic();

    expect($firstAtomic)->toBeInstanceOf(AtomicCacheInterface::class)
        ->and($secondAtomic)->toBeInstanceOf(AtomicCacheInterface::class)
        ->and($firstAtomic->setIfAbsent('claim', 'first', 30))->toBeTrue()
        ->and($secondAtomic->setIfAbsent('claim', 'second', 30))->toBeFalse()
        ->and($second->get('claim'))->toBe('first');

    $first->clear();
});

test('shared memory atomic consume returns a value exactly once across instances', function () {
    $first = Cache::sharedMemory('shm-consume');
    $second = Cache::sharedMemory('shm-consume');
    $atomic = $second->atomic();
    expect($atomic)->not->toBeNull();
    $first->set('state', ['ok' => true], 30);

    expect($atomic->getAndDelete('state', 'missing'))->toBe(['ok' => true])
        ->and($first->get('state'))->toBeNull()
        ->and($atomic->getAndDelete('state', 'missing'))->toBe('missing');

    $first->clear();
});

test('shared memory atomic set reclaims expired and tag-invalidated state', function () {
    $cache = Cache::sharedMemory('shm-stale');
    $atomic = $cache->atomic();
    expect($atomic)->not->toBeNull();

    $cache->set('expired', 'old', 1);
    usleep(2_000_000);
    expect($atomic->setIfAbsent('expired', 'new', 30))->toBeTrue()
        ->and($cache->get('expired'))->toBe('new');

    $cache->setTagged('tagged', 'old', ['group'], 30);
    $cache->invalidateTag('group');
    expect($atomic->setIfAbsent('tagged', 'new', 30))->toBeTrue()
        ->and($cache->get('tagged'))->toBe('new');

    $cache->clear();
});

test('shared memory atomic claim has one winner under process contention', function () {
    if (!function_exists('pcntl_fork')) {
        $this->markTestSkipped('pcntl is required for the shared-memory contention test.');
    }

    $namespace = 'shm-contention-' . getmypid();
    $cache = Cache::sharedMemory($namespace);
    $cache->clear();
    $directory = sys_get_temp_dir() . '/cachelayer-shm-contention-' . uniqid('', true);
    mkdir($directory, 0700, true);
    $children = [];

    for ($worker = 0; $worker < 4; $worker++) {
        $pid = pcntl_fork();
        if ($pid === 0) {
            $workerCache = Cache::sharedMemory($namespace);
            $atomic = $workerCache->atomic();
            $won = $atomic?->setIfAbsent('claim', $worker, 30) === true;
            file_put_contents($directory . '/' . getmypid(), $won ? '1' : '0');
            exit(0);
        }
        if ($pid > 0) {
            $children[] = $pid;
        }
    }

    foreach ($children as $pid) {
        pcntl_waitpid($pid, $status);
        expect(pcntl_wexitstatus($status))->toBe(0);
    }

    $wins = 0;
    foreach (glob($directory . '/*') ?: [] as $file) {
        $wins += file_get_contents($file) === '1' ? 1 : 0;
        unlink($file);
    }
    rmdir($directory);

    expect($wins)->toBe(1)
        ->and($cache->has('claim'))->toBeTrue();

    $cache->clear();
});
