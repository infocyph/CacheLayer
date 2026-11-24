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
        expect(Cache::scylla('scylla-tests'))->toBeInstanceOf(Cache::class);

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
    $base = rtrim($endpoint, '/');
    $context = stream_context_create([
        'http' => [
            'timeout' => 1.5,
            'ignore_errors' => true,
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
