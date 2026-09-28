<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cache\Adapter\MongoDbCacheAdapter;
use Infocyph\CacheLayer\Cache\Cache;

$mongoDsn = getenv('IC_MONGODB_DSN');
$mongoDatabase = getenv('IC_SERVICE_DATABASE') ?: 'phpforge';

if (!is_string($mongoDsn) || $mongoDsn === '') {
    throw new RuntimeException('IC_MONGODB_DSN is required for the configured MongoDB integration matrix.');
}
if (!class_exists(MongoDB\Client::class)) {
    throw new RuntimeException('mongodb/mongodb is required for the configured MongoDB integration matrix.');
}

beforeEach(function () use ($mongoDsn, $mongoDatabase) {
    $client = new MongoDB\Client($mongoDsn);
    $client->selectDatabase($mongoDatabase)->command(['ping' => 1]);

    $collectionName = 'cachelayer_real_' . getmypid() . '_' . bin2hex(random_bytes(4));
    $this->mongoClient = $client;
    $this->mongoCollection = $client->selectCollection($mongoDatabase, $collectionName);
    $this->mongoCache = new Cache(new MongoDbCacheAdapter($this->mongoCollection, 'mongo-real'));
});

afterEach(function () {
    $this->mongoCollection->drop();
});

test('real MongoDB stores, expires, tags, and clears cache records', function () {
    expect($this->mongoCache->set('value', ['ok' => true], 30))->toBeTrue()
        ->and($this->mongoCache->get('value'))->toBe(['ok' => true])
        ->and($this->mongoCache->setTagged('tagged', 'v1', ['group'], 30))->toBeTrue()
        ->and($this->mongoCache->get('tagged'))->toBe('v1')
        ->and($this->mongoCache->invalidateTag('group'))->toBeTrue()
        ->and($this->mongoCache->get('tagged'))->toBeNull()
        ->and($this->mongoCache->set('clear-me', 'value'))->toBeTrue()
        ->and($this->mongoCache->clear())->toBeTrue()
        ->and($this->mongoCache->get('clear-me'))->toBeNull();

    expect($this->mongoCache->set('expires', 'soon', 1))->toBeTrue();
    usleep(2_000_000);
    expect($this->mongoCache->get('expires'))->toBeNull();
});

test('real MongoDB atomic claim has exactly one process winner', function () use ($mongoDsn, $mongoDatabase) {
    if (!function_exists('pcntl_fork')) {
        throw new RuntimeException('pcntl is required for real MongoDB atomic contention coverage.');
    }

    $collectionName = $this->mongoCollection->getCollectionName();
    $children = [];
    for ($worker = 0; $worker < 8; ++$worker) {
        $pid = pcntl_fork();
        if ($pid === 0) {
            $client = new MongoDB\Client($mongoDsn);
            $collection = $client->selectCollection($mongoDatabase, $collectionName);
            $cache = new Cache(new MongoDbCacheAdapter($collection, 'mongo-real'));
            $won = $cache->atomic()?->setIfAbsent('claim', (string) $worker, 30) === true;
            pcntl_exec('/bin/sh', ['-c', $won ? 'true' : 'false']);

            throw new RuntimeException('Unable to terminate forked MongoDB contention process.');
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

    $freshClient = new MongoDB\Client($mongoDsn);
    $freshCollection = $freshClient->selectCollection($mongoDatabase, $collectionName);
    $freshCache = new Cache(new MongoDbCacheAdapter($freshCollection, 'mongo-real'));

    expect($wins)->toBe(1)
        ->and($freshCache->get('claim'))->toBeString();
});
