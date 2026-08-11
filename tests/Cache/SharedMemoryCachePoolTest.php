<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\CacheLayer\Cache\Adapter\SharedMemoryCacheAdapter;

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
