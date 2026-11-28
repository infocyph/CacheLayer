<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Tests\Cache;

use Infocyph\CacheLayer\Cache\Adapter\ScyllaDbCacheAdapter;
use Infocyph\CacheLayer\Tests\Cache\Support\ScyllaSessionDouble;
use RuntimeException;
use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\CacheLayer\Exceptions\CacheInvalidArgumentException;
use Infocyph\CacheLayer\Support\OptionalCassandra;

$scyllaFixture = new class(new ScyllaSessionDouble(), Cache::nullStore()) {
    public function __construct(public ScyllaSessionDouble $session, public Cache $cache) {}
};

beforeEach(function () use ($scyllaFixture): void {
    $scyllaFixture->session = new ScyllaSessionDouble();

    $scyllaFixture->cache = new Cache(new ScyllaDbCacheAdapter(
        $scyllaFixture->session,
        'cachelayer',
        'cachelayer_entries',
        'scylla-tests',
    ));
});

test('scylladb adapter stores and retrieves values', function () use ($scyllaFixture): void {
    $scyllaFixture->cache->set('k', 'value');

    expect($scyllaFixture->cache->get('k'))->toBe('value');
});

test('scylladb adapter clears namespace entries', function () use ($scyllaFixture): void {
    $scyllaFixture->cache->set('a', 1);
    $scyllaFixture->cache->set('b', 2);

    $scyllaFixture->cache->clear();

    expect($scyllaFixture->cache->getMultiple(['a', 'b']))->toBe(['a' => null, 'b' => null]);
});

test('scylladb cache factory accepts injected session', function () use ($scyllaFixture): void {
    $cache = Cache::scylla('scylla-tests', $scyllaFixture->session, 'cachelayer', 'cachelayer_entries');
    $cache->set('x', 'X');

    expect($cache->get('x'))->toBe('X');
});

test('scylladb cache factory handles the optional extension explicitly', function (): void {
    if (OptionalCassandra::available()) {
        $cache = Cache::scylla('scylla-tests');
        expect($cache->set('optional-extension', true))->toBeTrue()
            ->and($cache->get('optional-extension'))->toBeTrue();

        return;
    }

    expect(fn () => Cache::scylla('scylla-tests'))
        ->toThrow(CacheInvalidArgumentException::class);
});

test('scylladb groups bulk reads and writes by configured bucket', function () use ($scyllaFixture): void {
    $cache = Cache::scylla('bucket-tests', $scyllaFixture->session, 'cachelayer', 'cachelayer_entries', 1);
    $cache->setMultiple(['a' => 1, 'b' => 2, 'c' => 3]);
    $reads = $scyllaFixture->session->bucketReads;

    expect($cache->getMultiple(['c', 'missing', 'a']))
        ->toBe(['c' => 3, 'missing' => null, 'a' => 1])
        ->and($scyllaFixture->session->writeBatches)->toBeGreaterThanOrEqual(1)
        ->and($scyllaFixture->session->bucketReads)->toBe($reads + 1);
});

/**
 * @return array{endpoint:string}|null
 */
function scylladbAlternatorIntegrationContext(): ?array
{
    $endpoint = \Infocyph\CacheLayer\Tests\Support\CacheFixture::environment('IC_SCYLLADB_ENDPOINT') ?? \Infocyph\CacheLayer\Tests\Support\CacheFixture::environment('CACHELAYER_SCYLLADB_ENDPOINT') ?? 'http://127.0.0.1:8000';
    $base = match (rtrim($endpoint, '/')) {
        'http://127.0.0.1:8000' => 'http://127.0.0.1:8000',
        'http://127.0.0.1:8001' => 'http://127.0.0.1:8001',
        'http://127.0.0.1:8002' => 'http://127.0.0.1:8002',
        'http://localhost:8000' => 'http://localhost:8000',
        default => throw new RuntimeException('Alternator tests require an approved local fixture endpoint.'),
    };
    $context = stream_context_create([
        'http' => [
            'timeout' => 1.5,
            'ignore_errors' => true,
            'follow_location' => 0,
        ],
    ]);

    $health = scylladbHttpGet($base . '/', $context);
    if (!is_string($health) || $health === '') {
        return null;
    }

    return ['endpoint' => $base];
}

/** @param resource $context */
function scylladbHttpGet(string $url, mixed $context): ?string
{
    set_error_handler(static fn (): bool => true);

    try {
        $result = file_get_contents($url, false, $context);
    } finally {
        restore_error_handler();
    }

    if (!is_string($result) || $result === '') {
        return null;
    }

    return $result;
}

test('scylladb alternator health endpoint is reachable', function (): void {
    $integration = scylladbAlternatorIntegrationContext();
    if ($integration === null) {
        throw new RuntimeException('ScyllaDB Alternator service is required for the configured cache test matrix.');
    }

    $context = stream_context_create([
        'http' => [
            'timeout' => 1.5,
            'ignore_errors' => true,
            'follow_location' => 0,
        ],
    ]);

    $response = scylladbHttpGet($integration['endpoint'] . '/', $context);

    expect(is_string($response))->toBeTrue()
        ->and(str_contains(strtolower((string) $response), 'healthy'))->toBeTrue();
});

test('scylladb alternator localnodes endpoint returns json list', function (): void {
    $integration = scylladbAlternatorIntegrationContext();
    if ($integration === null) {
        throw new RuntimeException('ScyllaDB Alternator service is required for the configured cache test matrix.');
    }

    $context = stream_context_create([
        'http' => [
            'timeout' => 1.5,
            'ignore_errors' => true,
            'follow_location' => 0,
        ],
    ]);

    $response = scylladbHttpGet($integration['endpoint'] . '/localnodes', $context);
    $decoded = is_string($response) ? json_decode($response, true) : null;

    expect(is_array($decoded))->toBeTrue();
});


test('scylladb tag initialization preserves the first generation', function () use ($scyllaFixture): void {
    $first = Cache::scylla('tag-race', $scyllaFixture->session, 'cachelayer', 'cachelayer_entries', 1);
    $second = Cache::scylla('tag-race', $scyllaFixture->session, 'cachelayer', 'cachelayer_entries', 1);

    expect($first->setTagged('one', 'A', ['shared']))->toBeTrue()
        ->and($second->setTagged('two', 'B', ['shared']))->toBeTrue()
        ->and($first->get('one'))->toBe('A')
        ->and($second->get('two'))->toBe('B');
});

test('Scylla bulk reads isolate both namespace and bucket', function (): void {
    $session = new ScyllaSessionDouble();
    $insert = 'INSERT INTO cachelayer_entries';
    $session->execute($insert, ['arguments' => ['a', 0, 'same', 'expected', null]]);
    $session->execute($insert, ['arguments' => ['b', 0, 'same', 'wrong-namespace', null]]);
    $session->execute($insert, ['arguments' => ['a', 1, 'same', 'wrong-bucket', null]]);

    expect($session->execute('SELECT ckey, payload, expires FROM cachelayer_entries', ['arguments' => ['a', 0, 'same']]))
        ->toBe([['ckey' => 'same', 'payload' => 'expected', 'expires' => null]]);
});


test('Scylla opt-in LWT mode supports authoritative claims, replacement and consume', function (): void {
    $session = new ScyllaSessionDouble();
    $first = Cache::scylla('lwt-tests', $session, atomicMode: true);
    $second = Cache::scylla('lwt-tests', $session, atomicMode: true);
    $atomic = $first->atomic() ?? throw new RuntimeException('Scylla atomic mode required.');
    $peer = $second->atomic() ?? throw new RuntimeException('Scylla atomic peer required.');
    expect(Cache::scylla('default', $session)->atomic())->toBeNull()
        ->and($atomic->setIfAbsent('claim', 1, 60))->toBeTrue()
        ->and($peer->setIfAbsent('claim', 2))->toBeFalse()
        ->and($second->set('claim', 3))->toBeTrue()
        ->and($atomic->compareAndSet('claim', 3, 4))->toBeTrue()
        ->and($peer->getAndDelete('claim'))->toBe(4)
        ->and($atomic->getAndDelete('claim', 'missing'))->toBe('missing')
        ->and($first->set('clear', 5))->toBeTrue()
        ->and($second->clear())->toBeTrue()
        ->and($first->has('clear'))->toBeFalse();
});

test('Scylla LWT unknown result fails closed rather than being replayed', function (): void {
    $session = new ScyllaSessionDouble();
    $cache = Cache::scylla('lwt-failure', $session, options: new \Infocyph\CacheLayer\Cache\CacheOptions(failOpen: false), atomicMode: true);
    $atomic = $cache->atomic() ?? throw new RuntimeException('Scylla atomic mode required.');
    $session->invalidLwtResponse = true;
    expect(fn (): bool => $atomic->setIfAbsent('claim', 1))->toThrow(\Infocyph\CacheLayer\Exceptions\CacheBackendException::class);
});
