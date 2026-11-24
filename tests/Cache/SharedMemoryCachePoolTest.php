<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cache\Adapter\SharedMemoryCacheAdapter;
use Infocyph\CacheLayer\Cache\AtomicCacheInterface;
use Infocyph\CacheLayer\Cache\Cache;

if (! function_exists('shm_attach')) {
    throw new RuntimeException('System V shared memory is required for the configured cache test matrix.');
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

    if (!is_string($tokenFile) || !is_file($tokenFile)) {
        throw new RuntimeException('Expected a shared-memory token file.');
    }
    $permissions = fileperms($tokenFile);
    if ($permissions === false) {
        throw new RuntimeException('Unable to read token file permissions.');
    }
    expect($permissions & 0x0002)->toBe(0);

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
    $firstAtomic = $first->atomic() ?? throw new \RuntimeException('The first store must support atomic operations.');
    $secondAtomic = $second->atomic() ?? throw new \RuntimeException('The second store must support atomic operations.');

    expect($firstAtomic->setIfAbsent('claim', 'first', 30))->toBeTrue()
        ->and($secondAtomic->setIfAbsent('claim', 'second', 30))->toBeFalse()
        ->and($second->get('claim'))->toBe('first');

    $first->clear();
});

test('shared memory compare-and-set is strict and visible across instances', function () {
    $first = Cache::sharedMemory('shm-cas');
    $second = Cache::sharedMemory('shm-cas');
    $atomic = $second->atomic() ?? throw new \RuntimeException('The test requires atomic cache capability.');
    $first->set('state', 1, 30);

    expect($atomic->compareAndSet('state', '1', 2, 30))->toBeFalse()
        ->and($atomic->compareAndSet('state', 1, 2, 30))->toBeTrue()
        ->and($atomic->compareAndSet('state', 1, 3, 30))->toBeFalse()
        ->and($first->get('state'))->toBe(2);

    $first->clear();
});

test('shared memory compare-and-set validates tagged state inside its lock domain', function () {
    $cache = Cache::sharedMemory('shm-tagged-cas');
    $atomic = $cache->atomic() ?? throw new \RuntimeException('The test requires atomic cache capability.');

    $cache->setTagged('current', 'v1', ['group-current'], 30);
    expect($atomic->compareAndSet('current', 'v1', 'v2', 30))->toBeTrue()
        ->and($cache->get('current'))->toBe('v2');

    $cache->setTagged('stale', 'v1', ['group-stale'], 30);
    $cache->invalidateTag('group-stale');
    expect($atomic->compareAndSet('stale', 'v1', 'v2', 30))->toBeFalse();

    $cache->clear();
});

test('shared memory compare-and-set replacement honors ttl', function () {
    $cache = Cache::sharedMemory('shm-cas-ttl');
    $atomic = $cache->atomic() ?? throw new \RuntimeException('The test requires atomic cache capability.');
    $cache->set('state', 'v1', 30);

    expect($atomic->compareAndSet('state', 'v1', 'v2', 1))->toBeTrue();
    usleep(2_000_000);

    expect($cache->get('state'))->toBeNull();
    $cache->clear();
});

test('shared memory atomic consume returns a value exactly once across instances', function () {
    $first = Cache::sharedMemory('shm-consume');
    $second = Cache::sharedMemory('shm-consume');
    $atomic = $second->atomic() ?? throw new \RuntimeException('The test requires atomic cache capability.');
    $first->set('state', ['ok' => true], 30);

    expect($atomic->getAndDelete('state', 'missing'))->toBe(['ok' => true])
        ->and($first->get('state'))->toBeNull()
        ->and($atomic->getAndDelete('state', 'missing'))->toBe('missing');

    $first->clear();
});

test('shared memory atomic set reclaims expired and tag-invalidated state', function () {
    $cache = Cache::sharedMemory('shm-stale');
    $atomic = $cache->atomic() ?? throw new \RuntimeException('The test requires atomic cache capability.');

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
    expect(function_exists('pcntl_fork'))->toBeTrue();

    $namespace = 'shm-contention-' . bin2hex(random_bytes(8));
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
            if (file_put_contents($directory . '/' . $worker, $won ? '1' : '0') === false) {
                exit(2);
            }
            exit(0);
        }
        if ($pid < 0) {
            throw new RuntimeException('Unable to fork a cache contention process.');
        }
        $children[] = $pid;
    }

    foreach ($children as $pid) {
        $status = 0;
        if (pcntl_waitpid($pid, $status) !== $pid || !is_int($status) || !pcntl_wifexited($status)) {
            throw new RuntimeException('Shared-memory contender did not exit normally.');
        }
        expect(pcntl_wexitstatus($status))->toBe(0);
    }

    $wins = 0;
    $files = glob($directory . '/*');
    if ($files === false) {
        throw new RuntimeException('Unable to enumerate cache test files.');
    }
    foreach ($files as $file) {
        $wins += file_get_contents($file) === '1' ? 1 : 0;
        unlink($file);
    }
    rmdir($directory);

    expect($wins)->toBe(1)
        ->and($cache->has('claim'))->toBeTrue();

    $cache->clear();
});


test('shared memory atomic consume discards deferred overlays without resurrection', function () {
    $cache = Cache::sharedMemory('shm-deferred-consume');
    $atomic = $cache->atomic() ?? throw new \RuntimeException('The test requires atomic cache capability.');

    expect($cache->set('state', 'stored'))->toBeTrue()
        ->and($cache->saveDeferred($cache->getItem('state')->set('pending')))->toBeTrue()
        ->and($atomic->getAndDelete('state', 'missing'))->toBe('stored')
        ->and($atomic->getAndDelete('state', 'missing'))->toBe('missing')
        ->and($cache->commit())->toBeTrue()
        ->and($cache->get('state'))->toBeNull();

    $cache->clear();
});

test('shared memory commits queued items before the adapter is collected', function (): void {
    $namespace = 'shm-deferred-destruction';
    $reader = new SharedMemoryCacheAdapter($namespace);
    $reader->clear();

    try {
        $writer = new SharedMemoryCacheAdapter($namespace);
        $item = $writer->createItem('pending')->set('saved-on-release');
        expect($writer->saveDeferred($item))->toBeTrue()
            ->and($reader->getItem('pending')->isHit())->toBeFalse();
        unset($item, $writer);
        gc_collect_cycles();

        expect($reader->getItem('pending')->get())->toBe('saved-on-release');
    } finally {
        $reader->clear();
    }
});
