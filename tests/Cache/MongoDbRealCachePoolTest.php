<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Tests\Cache;

use Infocyph\CacheLayer\Cache\Adapter\MongoDbCacheAdapter;
use Infocyph\CacheLayer\Cache\Cache;
use MongoDB\Client;
use RuntimeException;

$mongoDsn = getenv('IC_MONGODB_DSN');
$mongoDatabase = \Infocyph\CacheLayer\Tests\Support\CacheFixture::environment('IC_SERVICE_DATABASE') ?? 'phpforge';

if (!is_string($mongoDsn) || $mongoDsn === '') {
    throw new RuntimeException('IC_MONGODB_DSN is required for the configured MongoDB integration matrix.');
}
if (!class_exists(Client::class)) {
    throw new RuntimeException('mongodb/mongodb is required for the configured MongoDB integration matrix.');
}

$mongoRealFixture = new class(Cache::nullStore()) {
    public ?\MongoDB\Collection $mongoCollection = null;

    public function __construct(public Cache $mongoCache) {}
};

beforeEach(function () use ($mongoDsn, $mongoDatabase, $mongoRealFixture): void {
    $client = new Client($mongoDsn);
    $client->selectDatabase($mongoDatabase)->command(['ping' => 1]);

    $collectionName = 'cachelayer_real_' . bin2hex(random_bytes(12));
    $mongoRealFixture->mongoCollection = $client->selectCollection($mongoDatabase, $collectionName);
    $mongoRealFixture->mongoCache = new Cache(new MongoDbCacheAdapter($mongoRealFixture->mongoCollection, 'mongo-real'));
});

afterEach(function () use ($mongoRealFixture): void {
    if (isset($mongoRealFixture->mongoCollection)) {
        $mongoRealFixture->mongoCollection->drop();
    }
});

test('real MongoDB stores, expires, tags, and clears cache records', function () use ($mongoRealFixture): void {
    expect($mongoRealFixture->mongoCache->set('value', ['ok' => true], 30))->toBeTrue()
        ->and($mongoRealFixture->mongoCache->get('value'))->toBe(['ok' => true])
        ->and($mongoRealFixture->mongoCache->setTagged('tagged', 'v1', ['group'], 30))->toBeTrue()
        ->and($mongoRealFixture->mongoCache->get('tagged'))->toBe('v1')
        ->and($mongoRealFixture->mongoCache->invalidateTag('group'))->toBeTrue()
        ->and($mongoRealFixture->mongoCache->get('tagged'))->toBeNull()
        ->and($mongoRealFixture->mongoCache->set('clear-me', 'value'))->toBeTrue()
        ->and($mongoRealFixture->mongoCache->clear())->toBeTrue()
        ->and($mongoRealFixture->mongoCache->get('clear-me'))->toBeNull();

    expect($mongoRealFixture->mongoCache->set('expires', 'soon', 1))->toBeTrue();
    usleep(2_000_000);
    expect($mongoRealFixture->mongoCache->get('expires'))->toBeNull();
});

test('real MongoDB atomic claim has exactly one process winner', function () use ($mongoDsn, $mongoDatabase, $mongoRealFixture): void {
    if (!function_exists('pcntl_fork')) {
        throw new RuntimeException('pcntl is required for real MongoDB atomic contention coverage.');
    }

    $collection = $mongoRealFixture->mongoCollection ?? throw new RuntimeException('MongoDB fixture setup did not run.');
    $collectionName = $collection->getCollectionName();
    $children = [];
    for ($worker = 0; $worker < 8; ++$worker) {
        $pid = pcntl_fork();
        if ($pid === 0) {
            $client = new Client($mongoDsn);
            $collection = $client->selectCollection($mongoDatabase, $collectionName);
            $cache = new Cache(new MongoDbCacheAdapter($collection, 'mongo-real'));
            $won = $cache->atomic()?->setIfAbsent('claim', (string) $worker, 30) === true;
            pcntl_exec('/bin/sh', ['-c', $won ? 'true' : 'false']);

            throw new RuntimeException('Unable to terminate forked MongoDB contention process.');
        }
        if ($pid < 0) {
            throw new RuntimeException('Unable to fork a MongoDB contention process.');
        }
        $children[] = $pid;
    }

    $wins = 0;
    foreach ($children as $pid) {
        if (pcntl_waitpid($pid, $status) !== $pid || !is_int($status) || !pcntl_wifexited($status)) {
            throw new RuntimeException('MongoDB contention process did not exit normally.');
        }
        $wins += pcntl_wexitstatus($status) === 0 ? 1 : 0;
    }

    $freshClient = new Client($mongoDsn);
    $freshCollection = $freshClient->selectCollection($mongoDatabase, $collectionName);
    $freshCache = new Cache(new MongoDbCacheAdapter($freshCollection, 'mongo-real'));

    expect($wins)->toBe(1)
        ->and($freshCache->get('claim'))->toBeString();
});
