<?php

use Infocyph\CacheLayer\Cache\Cache;

if (!function_exists('shm_attach')) {
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
