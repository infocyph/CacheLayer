<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cache\Adapter\ScyllaDbCacheAdapter;
use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\CacheLayer\Exceptions\CacheInvalidArgumentException;

beforeEach(function () {
    $this->session = new class
    {
        /** @var array<string, array{ckey:string,payload:string,expires:int|null}> */
        private array $rows = [];

        public int $bucketReads = 0;

        public int $writeBatches = 0;

        public function prepare(string $cql): string
        {
            return $cql;
        }

        /**
         * @return array<int, array<string, mixed>>
         */
        public function execute(mixed $statement, mixed $options = []): array
        {
            $cql = trim((string) $statement);
            $args = $this->extractArguments($options);

            if (str_starts_with($cql, 'CREATE TABLE')) {
                return [];
            }

            if (str_starts_with($cql, 'DELETE FROM') && str_contains($cql, 'AND ckey = ?')) {
                unset($this->rows[$this->rowKey($args)]);

                return [];
            }

            if (str_starts_with($cql, 'DELETE FROM') && str_contains($cql, 'ckey IN')) {
                $ns = (string) ($args[0] ?? '');
                $bucket = (int) ($args[1] ?? 0);
                foreach (array_slice($args, 2) as $key) {
                    unset($this->rows[$ns . ':' . $bucket . ':' . $key]);
                }

                return [];
            }

            if (str_starts_with($cql, 'DELETE FROM')) {
                $prefix = (string) ($args[0] ?? '') . ':' . (int) ($args[1] ?? 0) . ':';
                foreach (array_keys($this->rows) as $key) {
                    if (str_starts_with($key, $prefix)) {
                        unset($this->rows[$key]);
                    }
                }

                return [];
            }

            if (str_starts_with($cql, 'SELECT expires')) {
                $ns = (string) ($args[0] ?? '');
                $bucket = (int) ($args[1] ?? 0);
                $matching = [];
                foreach ($this->rows as $key => $row) {
                    if (str_starts_with($key, $ns . ':' . $bucket . ':')) {
                        $matching[] = $row;
                    }
                }

                return array_map(
                    static fn (array $row): array => ['expires' => $row['expires']],
                    $matching,
                );
            }

            if (str_starts_with($cql, 'SELECT payload, expires')) {
                $row = $this->rows[$this->rowKey($args)] ?? null;

                return is_array($row) ? [$row] : [];
            }

            if (str_starts_with($cql, 'SELECT ckey, payload, expires')) {
                $this->bucketReads++;
                $ns = (string) ($args[0] ?? '');
                $bucket = (int) ($args[1] ?? 0);
                $keys = array_map('strval', array_slice($args, 2));

                return array_values(array_filter(
                    $this->rows,
                    static fn(array $row): bool => in_array($row['ckey'], $keys, true),
                ));
            }

            if (str_starts_with($cql, 'BEGIN UNLOGGED BATCH')) {
                $this->writeBatches++;
                foreach (array_chunk($args, 5) as $row) {
                    $this->store($row);
                }

                return [];
            }

            if (str_starts_with($cql, 'INSERT INTO')) {
                $this->store($args);

                return [];
            }

            return [];
        }

        /**
         * @return array<int, mixed>
         */
        private function extractArguments(mixed $options): array
        {
            if (is_array($options) && is_array($options['arguments'] ?? null)) {
                return array_values($options['arguments']);
            }

            return [];
        }

        /** @param array<int, mixed> $row */
        private function rowKey(array $row): string
        {
            return (string) ($row[0] ?? '') . ':' . (int) ($row[1] ?? 0) . ':' . (string) ($row[2] ?? '');
        }

        /** @param array<int, mixed> $row */
        private function store(array $row): void
        {
            $key = $this->rowKey($row);
            $this->rows[$key] = [
                'ckey' => (string) ($row[2] ?? ''),
                'payload' => (string) ($row[3] ?? ''),
                'expires' => is_numeric($row[4] ?? null) ? (int) $row[4] : null,
            ];
        }
    };

    $this->cache = new Cache(new ScyllaDbCacheAdapter(
        $this->session,
        'cachelayer',
        'cachelayer_entries',
        'scylla-tests',
    ));
});

test('scylladb adapter stores and retrieves values', function () {
    $this->cache->set('k', 'value');

    expect($this->cache->get('k'))->toBe('value');
});

test('scylladb adapter clears namespace entries', function () {
    $this->cache->set('a', 1);
    $this->cache->set('b', 2);

    $this->cache->clear();

    expect($this->cache->getMultiple(['a', 'b']))->toBe(['a' => null, 'b' => null]);
});

test('scylladb cache factory accepts injected session', function () {
    $cache = Cache::scylla('scylla-tests', $this->session, 'cachelayer', 'cachelayer_entries');
    $cache->set('x', 'X');

    expect($cache->get('x'))->toBe('X');
});

test('scylladb cache factory requires extension when session is missing', function () {
    if (class_exists(Cassandra::class)) {
        $this->markTestSkipped('Cassandra extension loaded in this environment.');
    }

    expect(fn () => Cache::scylla('scylla-tests'))
        ->toThrow(CacheInvalidArgumentException::class);
});

test('scylladb groups bulk reads and writes by configured bucket', function () {
    $cache = Cache::scylla('bucket-tests', $this->session, 'cachelayer', 'cachelayer_entries', 1);
    $cache->setMultiple(['a' => 1, 'b' => 2, 'c' => 3]);
    $reads = $this->session->bucketReads;

    expect($cache->getMultiple(['c', 'missing', 'a']))
        ->toBe(['c' => 3, 'missing' => null, 'a' => 1])
        ->and($this->session->writeBatches)->toBeGreaterThanOrEqual(1)
        ->and($this->session->bucketReads)->toBe($reads + 1);
});

/**
 * @return array{endpoint:string}|null
 */
function scylladbAlternatorIntegrationContext(): ?array
{
    $endpoint = getenv('IC_SCYLLADB_ENDPOINT') ?: getenv('CACHELAYER_SCYLLADB_ENDPOINT') ?: 'http://127.0.0.1:8000';
    if (!is_string($endpoint) || $endpoint === '') {
        return null;
    }

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

function scylladbHttpGet(string $url, mixed $context): ?string
{
    $previous = set_error_handler(static fn (): bool => true);

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

test('scylladb alternator health endpoint is reachable', function () {
    $integration = scylladbAlternatorIntegrationContext();
    if ($integration === null) {
        $this->markTestSkipped('ScyllaDB Alternator integration unavailable (service missing).');
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

test('scylladb alternator localnodes endpoint returns json list', function () {
    $integration = scylladbAlternatorIntegrationContext();
    if ($integration === null) {
        $this->markTestSkipped('ScyllaDB Alternator integration unavailable (service missing).');
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
