<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cache\Adapter\ArrayCacheAdapter;
use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\CacheLayer\Exceptions\CacheInvalidArgumentException;

beforeEach(function () {
    $this->l1 = new ArrayCacheAdapter('l1');
    $this->l2 = new ArrayCacheAdapter('l2');
    $this->cache = Cache::tiered([$this->l1, $this->l2]);
});

test('tiered adapter writes through all pools', function () {
    $this->cache->set('k', 'value');

    expect($this->l1->getItem('k')->isHit())->toBeTrue()
        ->and($this->l2->getItem('k')->isHit())->toBeTrue();
});

test('tiered adapter promotes value from lower tier to upper tier', function () {
    $item = $this->l2->getItem('promote');
    $item->set('from-l2')->save();

    expect($this->l1->getItem('promote')->isHit())->toBeFalse();

    expect($this->cache->get('promote'))->toBe('from-l2')
        ->and($this->l1->getItem('promote')->isHit())->toBeTrue();
});

test('tiered cache supports descriptor array tiers', function () {
    $cache = Cache::tiered([
        ['driver' => 'memory', 'namespace' => 'tiered-l1'],
        ['driver' => 'memory', 'namespace' => 'tiered-l2'],
    ]);

    expect($cache->set('k', 'v'))->toBeTrue()
        ->and($cache->get('k'))->toBe('v');
});

test('tiered cache can skip L1 write-through on save', function () {
    $l1 = new ArrayCacheAdapter('skip-l1');
    $l2 = new ArrayCacheAdapter('skip-l2');
    $cache = Cache::tiered([$l1, $l2], writeToL1: false);

    $cache->set('x', 'X');

    expect($l1->getItem('x')->isHit())->toBeFalse()
        ->and($l2->getItem('x')->isHit())->toBeTrue();

    expect($cache->get('x'))->toBe('X')
        ->and($l1->getItem('x')->isHit())->toBeTrue();
});

test('tiered tag validation follows the authoritative last tier', function () {
    $l1 = new ArrayCacheAdapter('tier-disagreement');
    $l2 = new ArrayCacheAdapter('tier-disagreement');
    $cache = Cache::tiered([$l1, $l2]);
    $cache->setTagged('tagged', 'value', ['products']);

    expect($cache->get('tagged'))->toBe('value');
    $l2->rotateTagGenerations(['products']);
    expect($cache->get('tagged'))->toBeNull();
});

test('tiered cache rejects unsupported driver descriptors', function () {
    expect(fn() => Cache::tiered([['driver' => 'unknown-tier']]))
        ->toThrow(CacheInvalidArgumentException::class);
});


test('skipped L1 write-through invalidates promoted values before later reads', function () {
    $l1 = new ArrayCacheAdapter('skip-stale');
    $l2 = new ArrayCacheAdapter('skip-stale');
    $cache = Cache::tiered([$l1, $l2], writeToL1: false);

    expect($cache->set('single', 'old'))->toBeTrue()
        ->and($cache->get('single'))->toBe('old')
        ->and($l1->getItem('single')->get())->toBe('old')
        ->and($cache->set('single', 'new'))->toBeTrue()
        ->and($l1->getItem('single')->isHit())->toBeFalse()
        ->and($cache->get('single'))->toBe('new');

    expect($cache->setMultiple(['one' => 'old-1', 'two' => 'old-2']))->toBeTrue()
        ->and($cache->getMultiple(['one', 'two']))->toBe(['one' => 'old-1', 'two' => 'old-2'])
        ->and($cache->setMultiple(['one' => 'new-1', 'two' => 'new-2']))->toBeTrue()
        ->and($l1->getItem('one')->isHit())->toBeFalse()
        ->and($l1->getItem('two')->isHit())->toBeFalse()
        ->and($cache->getMultiple(['one', 'two']))->toBe(['one' => 'new-1', 'two' => 'new-2']);
});

test('tiered bulk reads preserve numeric-string logical keys', function () {
    $l1 = new ArrayCacheAdapter('numeric-tier');
    $l2 = new ArrayCacheAdapter('numeric-tier');
    $cache = Cache::tiered([$l1, $l2], writeToL1: false);

    foreach (['0', '123', '-1', '01'] as $key) {
        expect($cache->set($key, 'value-' . $key))->toBeTrue();
    }

    $actual = [];
    foreach ($cache->getMultiple(['0', '123', '-1', '01']) as $key => $value) {
        $actual[] = [$key, $value];
    }

    expect($actual)->toBe([
        ['0', 'value-0'],
        ['123', 'value-123'],
        ['-1', 'value--1'],
        ['01', 'value-01'],
    ]);
});


test('tiered L1 remains fenced after unrelated successful writes until full clear', function () {
    $l1 = new ArrayCacheAdapter('fenced-l1');
    $l2 = new ArrayCacheAdapter('fenced-l2');
    $cache = Cache::tiered([$l1, $l2]);
    $adapter = (new ReflectionClass($cache))->getProperty('adapter')->getValue($cache);

    expect($cache->set('x', 'old'))->toBeTrue();
    $l2->save($l2->getItem('x')->set('new'));

    $readable = new ReflectionProperty($adapter, 'l1Readable');
    $readable->setValue($adapter, false);

    expect($cache->get('x'))->toBe('new')
        ->and($cache->set('unrelated', 'value'))->toBeTrue()
        ->and($readable->getValue($adapter))->toBeFalse()
        ->and($cache->get('x'))->toBe('new')
        ->and($cache->delete('unrelated'))->toBeTrue()
        ->and($readable->getValue($adapter))->toBeFalse()
        ->and($cache->get('x'))->toBe('new')
        ->and($cache->clear())->toBeTrue()
        ->and($readable->getValue($adapter))->toBeTrue();
});

test('tier mutation failures fence reads until a complete clear', function (string $operation, bool $throws, bool $failOpen): void {
    $directory = sys_get_temp_dir() . '/cachelayer-tier-failure-' . bin2hex(random_bytes(6));
    $l1 = new \Infocyph\CacheLayer\Tests\Cache\Support\FaultingFileCacheAdapter('fault', $directory);
    $l2 = new ArrayCacheAdapter('fault');
    $cache = Cache::tiered([$l1, $l2], options: new \Infocyph\CacheLayer\Cache\CacheOptions(failOpen: $failOpen));
    $cache->set('x', 'old');
    $l2->save($l2->getItem('x')->set('new'));
    $l1->failure = $operation;
    $l1->throws = $throws;

    $mutate = match ($operation) {
        'clear' => fn() => $cache->clear(),
        'save' => fn() => $cache->set('unrelated', 'value'),
        'saveItems' => fn() => $cache->setMultiple(['unrelated' => 'value']),
        'deleteItem' => fn() => $cache->delete('unrelated'),
        'deleteItems' => fn() => $cache->deleteMultiple(['unrelated']),
    };

    try {
        if ($throws && !$failOpen) {
            expect($mutate)->toThrow(\Infocyph\CacheLayer\Exceptions\CacheBackendException::class);
        } else {
            expect($mutate())->toBeFalse();
        }
        $expected = $l2->getItem('x')->get();
        expect($cache->get('x'))->toBe($expected);
        $l1->failure = null;
        $cache->set('unrelated', 'recovered');
        expect($cache->get('x'))->toBe($expected)
            ->and($cache->clear())->toBeTrue()
            ->and($l1->getItem('x')->isHit())->toBeFalse()
            ->and($cache->get('x'))->toBeNull();
    } finally {
        $l1->failure = null;
        $l1->clear();
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($directory);
    }
})->with(['clear', 'save', 'saveItems', 'deleteItem', 'deleteItems'])->with([false, true])->with([false, true]);


test('skipped writes and failed promotions keep every upper tier fenced', function (string $operation, bool $throws, bool $bulk): void {
    $directory = sys_get_temp_dir() . '/cachelayer-tier-promotion-' . bin2hex(random_bytes(6));
    $l1 = new \Infocyph\CacheLayer\Tests\Cache\Support\FaultingFileCacheAdapter('promotion', $directory);
    $middle = new ArrayCacheAdapter('middle');
    $last = new ArrayCacheAdapter('last');
    $cache = Cache::tiered([$l1, $middle, $last], writeToL1: $operation !== 'skip');
    $cache->set('x', 'old');
    $cache->get('x');
    $last->save($last->getItem('x')->set('new'));
    $l1->throws = $throws;
    $l1->failure = $operation === 'skip' ? 'deleteItems' : ($bulk ? 'saveItems' : 'save');

    try {
        if ($operation === 'skip') {
            expect($bulk ? $cache->setMultiple(['x' => 'new']) : $cache->set('x', 'new'))->toBeFalse();
        } else {
            $last->save($last->getItem('promote')->set('authoritative'));
            $bulk ? $cache->getMultiple(['promote']) : $cache->get('promote');
        }
        expect($cache->get('x'))->toBe('new')
            ->and($cache->getMultiple(['x']))->toBe(['x' => 'new']);
        $l1->failure = null;
        $cache->set('unrelated', 'value');
        expect($cache->get('x'))->toBe('new');
        $cache->clear();
    } finally {
        $l1->failure = null;
        $l1->clear();
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($directory);
    }
})->with(['skip', 'promote'])->with([false, true])->with([false, true]);
