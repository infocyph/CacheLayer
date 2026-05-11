<?php

use Infocyph\CacheLayer\Cache\Adapter\ScyllaDbCacheAdapter;
use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\CacheLayer\Exceptions\CacheInvalidArgumentException;

beforeEach(function () {
    $this->session = new class
    {
        /** @var array<string, array<string, array{payload:string,expires:int|null}>> */
        private array $rows = [];

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
                $ns = (string) ($args[0] ?? '');
                $key = (string) ($args[1] ?? '');
                unset($this->rows[$ns][$key]);

                return [];
            }

            if (str_starts_with($cql, 'DELETE FROM')) {
                $ns = (string) ($args[0] ?? '');
                unset($this->rows[$ns]);

                return [];
            }

            if (str_starts_with($cql, 'SELECT expires')) {
                $ns = (string) ($args[0] ?? '');

                return array_map(
                    static fn (array $row): array => ['expires' => $row['expires']],
                    array_values($this->rows[$ns] ?? []),
                );
            }

            if (str_starts_with($cql, 'SELECT payload, expires')) {
                $ns = (string) ($args[0] ?? '');
                $key = (string) ($args[1] ?? '');
                $row = $this->rows[$ns][$key] ?? null;

                return is_array($row) ? [$row] : [];
            }

            if (str_starts_with($cql, 'INSERT INTO')) {
                $ns = (string) ($args[0] ?? '');
                $key = (string) ($args[1] ?? '');
                $payload = (string) ($args[2] ?? '');
                $expires = $args[3] ?? null;

                $this->rows[$ns][$key] = [
                    'payload' => $payload,
                    'expires' => is_numeric($expires) ? (int) $expires : null,
                ];

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

    expect($this->cache->get('k'))->toBe('value')
        ->and($this->cache->count())->toBe(1);
});

test('scylladb adapter clears namespace entries', function () {
    $this->cache->set('a', 1);
    $this->cache->set('b', 2);

    $this->cache->clear();

    expect($this->cache->count())->toBe(0);
});

test('scylladb cache factory accepts injected session', function () {
    $cache = Cache::scyllaDb('scylla-tests', $this->session, 'cachelayer', 'cachelayer_entries');
    $cache->set('x', 'X');

    expect($cache->get('x'))->toBe('X');
});

test('scylladb cache factory requires extension when session is missing', function () {
    if (class_exists(Cassandra::class)) {
        $this->markTestSkipped('Cassandra extension loaded in this environment.');
    }

    expect(fn () => Cache::scyllaDb('scylla-tests'))
        ->toThrow(CacheInvalidArgumentException::class);
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
