<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Tests\Cache;

use Infocyph\CacheLayer\Cache\Adapter\PdoCacheAdapter;
use Infocyph\CacheLayer\Cache\Adapter\PdoCacheSchema;
use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\CacheLayer\Cache\Lock\PdoLockProvider;
use Infocyph\CacheLayer\Tests\Support\CacheFixture;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

if (! in_array('pgsql', PDO::getAvailableDrivers(), true)) {
    throw new RuntimeException('PDO PostgreSQL is required for the configured cache test matrix.');
}

$dsn = \Infocyph\CacheLayer\Tests\Support\CacheFixture::environment('IC_POSTGRES_DSN') ?? \Infocyph\CacheLayer\Tests\Support\CacheFixture::environment('CACHELAYER_PG_DSN') ?? 'pgsql:host=127.0.0.1;port=5432;dbname=cachelayer';
$user = \Infocyph\CacheLayer\Tests\Support\CacheFixture::environment('IC_SERVICE_USERNAME') ?? \Infocyph\CacheLayer\Tests\Support\CacheFixture::environment('IC_POSTGRES_USER') ?? \Infocyph\CacheLayer\Tests\Support\CacheFixture::environment('CACHELAYER_PG_USER') ?? 'postgres';
$pass = \Infocyph\CacheLayer\Tests\Support\CacheFixture::environment('IC_SERVICE_PASSWORD') ?? \Infocyph\CacheLayer\Tests\Support\CacheFixture::environment('IC_POSTGRES_PASSWORD') ?? \Infocyph\CacheLayer\Tests\Support\CacheFixture::environment('CACHELAYER_PG_PASS') ?? 'postgres';

try {
    $probe = new PDO($dsn, $user, $pass);
    $probe->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $probe->query('SELECT 1');
} catch (Throwable $failure) {
    throw new RuntimeException('PostgreSQL service is required for the configured cache test matrix.', 0, $failure);
}

$pdopgsqlFixtureCache = Cache::nullStore();

beforeEach(function () use ($dsn, $user, $pass, &$pdopgsqlFixtureCache): void {
    $pdopgsqlFixtureCache = Cache::pdo('pg-tests', $dsn, $user, $pass, null, 'cachelayer_entries');
    $pdopgsqlFixtureCache->clear();
});

afterEach(function () use (&$pdopgsqlFixtureCache): void {
    $pdopgsqlFixtureCache->clear();
});

test('pdo adapter set and get on pgsql', function () use (&$pdopgsqlFixtureCache): void {
    expect($pdopgsqlFixtureCache->set('alpha', 11))->toBeTrue()
        ->and($pdopgsqlFixtureCache->get('alpha'))->toBe(11);
});

test('pdo adapter ttl expiry on pgsql', function () use (&$pdopgsqlFixtureCache): void {
    $pdopgsqlFixtureCache->set('ttl', 'v', 1);
    usleep(2_000_000);

    expect($pdopgsqlFixtureCache->get('ttl'))->toBeNull();
});

test('pdo adapter bulk delete on pgsql', function () use (&$pdopgsqlFixtureCache): void {
    $pdopgsqlFixtureCache->set('a', 'A');
    $pdopgsqlFixtureCache->set('b', 'B');

    $pdopgsqlFixtureCache->deleteMultiple(['a']);
    expect($pdopgsqlFixtureCache->get('a'))->toBeNull()
        ->and($pdopgsqlFixtureCache->get('b'))->toBe('B');
});

test('PostgreSQL advisory locks accept native PDO booleans and release ownership', function () use ($dsn, $user, $pass) {
    $connect = static function () use ($dsn, $user, $pass): PDO {
        return new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    };
    $holder = new PdoLockProvider($connect(), 'cachelayer:test:');
    $contender = new PdoLockProvider($connect(), 'cachelayer:test:');
    $key = 'postgres-lock-' . bin2hex(random_bytes(8));
    $handle = $holder->acquire($key, 0.0, 5.0);

    expect($handle)->not->toBeNull()
        ->and($holder->refresh($handle, 5.0))->toBeTrue()
        ->and($contender->acquire($key, 0.0, 5.0))->toBeNull();

    $holder->release($handle);

    $replacement = $contender->acquire($key, 0.0, 5.0);
    expect($holder->refresh($handle, 5.0))->toBeFalse()
        ->and($replacement)->not->toBeNull();
    $contender->release($replacement);
});


test('PostgreSQL bulk preserves binary, null, false, zero and misses', function () use (&$pdopgsqlFixtureCache): void {
    $values = ['nil' => null, 'zero' => 0, 'false' => false, 'binary' => "a\0\xffb"];
    expect($pdopgsqlFixtureCache->setMultiple($values, 600))->toBeTrue()
        ->and(iterator_to_array($pdopgsqlFixtureCache->getMultiple([...array_keys($values), 'missing'], 'absent')))
        ->toBe([...$values, 'missing' => 'absent']);
    foreach ($values as $key => $value) {
        expect($pdopgsqlFixtureCache->has($key))->toBeTrue()
            ->and($pdopgsqlFixtureCache->get($key, 'absent'))->toBe($value);
    }
    expect($pdopgsqlFixtureCache->has('missing'))->toBeFalse();
});

test('PostgreSQL atomic writes preserve binary payloads', function () use (&$pdopgsqlFixtureCache): void {
    $atomic = $pdopgsqlFixtureCache->atomic();
    if ($atomic === null) {
        throw new RuntimeException('PostgreSQL atomic capability is required.');
    }
    $first = "a\0\xffb";
    $second = "c\0\xfed";
    expect($atomic->setIfAbsent('binary-atomic', $first, 600))->toBeTrue()
        ->and($pdopgsqlFixtureCache->get('binary-atomic'))->toBe($first)
        ->and($atomic->compareAndSet('binary-atomic', $first, $second, 600))->toBeTrue()
        ->and($atomic->getAndDelete('binary-atomic'))->toBe($second)
        ->and($pdopgsqlFixtureCache->has('binary-atomic'))->toBeFalse();
});


test('PostgreSQL schema aliases share the same transaction lock', function () use ($dsn, $user, $pass): void {
    $holder = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $peer = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    $holder->beginTransaction();
    try {
        PdoCacheSchema::install($holder, 'CacheLayer_Entries');
        expect($holder->inTransaction())->toBeTrue();
        $result = $peer->query("SELECT pg_try_advisory_xact_lock(hashtext('cachelayer:schema'), hashtext('cachelayer_entries'))");
        if (!$result instanceof \PDOStatement) {
            throw new RuntimeException('PostgreSQL advisory-lock probe failed.');
        }
        expect($result->fetchColumn())->toBeFalse();
    } finally {
        $holder->rollBack();
    }
});

test('PostgreSQL independent workers initialize a fresh schema with mixed spelling and elect one atomic winner', function () use ($dsn, $user, $pass): void {
    $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('DROP TABLE IF EXISTS cachelayer_schema_race');
    try {
        $results = \Infocyph\CacheLayer\Tests\Support\CacheFixture::cacheRace([
            'backend' => 'pdo', 'operation' => 'insert', 'namespace' => 'schema-race',
            'dsn' => $dsn, 'user' => $user, 'password' => $pass,
        ]);
        $winners = array_values(array_filter($results, static fn(int $value): bool => $value !== 0));
        $peer = Cache::pdo('schema-race', $dsn, $user, $pass, table: 'cachelayer_schema_race');
        expect($results)->toHaveCount(12)
            ->and($winners)->toHaveCount(1)
            ->and($peer->get('race'))->toBe($winners[0] ?? null)
            ->and(\Infocyph\CacheLayer\Tests\Support\CacheFixture::countRows($pdo, 'SELECT COUNT(*) FROM cachelayer_schema_race'))->toBe(1);
    } finally {
        $pdo->exec('DROP TABLE IF EXISTS cachelayer_schema_race');
    }
});


test('PostgreSQL independent conditional inserts elect one winner on existing schema', function (bool $expired, string $isolation) use ($dsn, $user, $pass): void {
    $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $cache = Cache::pdo('pgsql-insert-race', $dsn, $user, $pass, table: 'cachelayer_schema_race');
    $cache->clear();

    try {
        if ($expired) {
            expect($cache->set('race', 'expired', 600))->toBeTrue();
            $pdo->exec("UPDATE cachelayer_schema_race SET expires = 1 WHERE namespace = 'pgsql-insert-race' AND kind = 'data' AND cache_key = 'race'");
        }

        $results = CacheFixture::cacheRace([
            'backend' => 'pdo',
            'operation' => 'insert',
            'namespace' => 'pgsql-insert-race',
            'isolation' => $isolation,
            'dsn' => $dsn,
            'user' => $user,
            'password' => $pass,
        ]);
        $winners = array_values(array_filter($results, static fn(int $result): bool => $result > 0));
        expect($results)->toHaveCount(12)
            ->and($winners)->toHaveCount(1)
            ->and($cache->get('race'))->toBe($winners[0] ?? null);
    } finally {
        $cache->clear();
    }
})->with([[false, 'default'], [true, 'default'], [false, 'serializable'], [true, 'serializable']]);

test('PostgreSQL atomic operations recover only from bounded transaction aborts', function (string $key, int $attempts, bool $success, string $operation) use ($dsn, $user, $pass): void {
    $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('CREATE TEMP TABLE cachelayer_retry_test (namespace TEXT NOT NULL, kind TEXT NOT NULL, cache_key TEXT NOT NULL, payload BYTEA NOT NULL, expires BIGINT, PRIMARY KEY (namespace, kind, cache_key))');
    $pdo->exec('CREATE TEMP SEQUENCE cachelayer_retry_attempts');
    $pdo->exec(<<<'SQL'
        CREATE FUNCTION pg_temp.cachelayer_retry_failure() RETURNS trigger LANGUAGE plpgsql AS $$
        BEGIN
            IF TG_OP = 'DELETE' THEN
                IF nextval('pg_temp.cachelayer_retry_attempts') = 1 THEN
                    RAISE EXCEPTION 'test consume serialization failure' USING ERRCODE = '40001';
                END IF;
                RETURN OLD;
            END IF;
            IF nextval('pg_temp.cachelayer_retry_attempts') = 1 OR NEW.cache_key = 'exhausted' THEN
                IF NEW.cache_key = 'deadlock' THEN
                    RAISE EXCEPTION 'test deadlock' USING ERRCODE = '40P01';
                ELSIF NEW.cache_key = 'permanent' THEN
                    RAISE EXCEPTION 'test permanent failure' USING ERRCODE = '23514';
                ELSE
                    RAISE EXCEPTION 'test serialization failure' USING ERRCODE = '40001';
                END IF;
            END IF;
            RETURN NEW;
        END;
        $$
        SQL);
    $adapter = new PdoCacheAdapter('retry-test', $dsn, pdo: $pdo, table: 'cachelayer_retry_test', initializeSchema: false);
    if ($operation !== 'insert') {
        expect($adapter->save($adapter->createItem($key)->set(1)))->toBeTrue();
    }
    $pdo->exec('CREATE TRIGGER cachelayer_retry_failure BEFORE INSERT OR UPDATE OR DELETE ON cachelayer_retry_test FOR EACH ROW EXECUTE FUNCTION pg_temp.cachelayer_retry_failure()');
    $item = $adapter->createItem($key)->set(42);

    if ($operation === 'consume') {
        expect($adapter->atomicGetAndDelete($key)->get())->toBe(1)
            ->and($adapter->getItem($key)->isHit())->toBeFalse();
    } elseif ($success) {
        $stored = $operation === 'insert'
            ? $adapter->atomicSetIfAbsent($item)
            : $adapter->atomicCompareAndSet($key, 1, $item);
        expect($stored)->toBeTrue()
            ->and($adapter->getItem($key)->get())->toBe(42);
    } else {
        try {
            $adapter->atomicSetIfAbsent($item);
            throw new RuntimeException('Expected PostgreSQL transaction failure.');
        } catch (PDOException $failure) {
            expect($failure->errorInfo[0] ?? null)->toBe($key === 'permanent' ? '23514' : '40001');
        }
        expect($adapter->getItem($key)->isHit())->toBeFalse();
    }

    expect($pdo->inTransaction())->toBeFalse()
        ->and(CacheFixture::countRows($pdo, 'SELECT last_value FROM pg_temp.cachelayer_retry_attempts'))->toBe($attempts);
})->with([
    ['serialization', 2, true, 'insert'],
    ['deadlock', 2, true, 'insert'],
    ['exhausted', 8, false, 'insert'],
    ['permanent', 1, false, 'insert'],
    ['serialization', 2, true, 'compare'],
    ['deadlock', 2, true, 'compare'],
    ['serialization', 2, true, 'consume'],
]);

test('PostgreSQL native advisory lock is released on session loss', function () use ($dsn, $user, $pass): void {
    $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $peer = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $holder = PdoLockProvider::strict($pdo);
    $contender = PdoLockProvider::strict($peer);
    $key = 'postgres-session-loss-' . bin2hex(random_bytes(8));
    $handle = $holder->acquire($key, 0.0);
    expect($holder->effectiveLockProvider())->toBe($holder)
        ->and($handle)->not->toBeNull()
        ->and($holder->acquire($key, 0.0))->toBeNull()
        ->and($contender->acquire($key, 0.0))->toBeNull();

    $pid = CacheFixture::countRows($pdo, 'SELECT pg_backend_pid()');
    $statement = $peer->prepare('SELECT CAST(pg_terminate_backend(CAST(? AS integer)) AS integer)');
    $statement->execute([$pid]);
    expect((int) $statement->fetchColumn())->toBe(1);
    $statement->closeCursor();

    $replacement = $contender->acquire($key, 2.0);
    try {
        expect($replacement)->not->toBeNull()
            ->and($holder->refresh($handle, 30.0))->toBeFalse();
        $holder->release($handle);
        expect($contender->refresh($replacement, 30.0))->toBeTrue();
    } finally {
        $contender->release($replacement);
    }
});


test('PostgreSQL native locks reject same-session reentry and detect external unlock', function () use ($dsn, $user, $pass): void {
    $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $holder = PdoLockProvider::strict($pdo);
    $peer = PdoLockProvider::strict($pdo);
    $key = 'pgsql-session-' . bin2hex(random_bytes(8));
    $handle = $holder->acquire($key, 0.0);
    try {
        expect($handle)->not->toBeNull()
            ->and($peer->acquire($key, 0.0))->toBeNull()
            ->and($holder->refresh($handle, 30.0))->toBeTrue();
        $pdo->query('SELECT pg_advisory_unlock_all()');
        expect($holder->refresh($handle, 30.0))->toBeFalse();
    } finally {
        $holder->release($handle);
    }
    $replacement = $peer->acquire($key, 0.0);
    try {
        expect($replacement)->not->toBeNull()
            ->and($holder->refresh($handle, 30.0))->toBeFalse();
    } finally {
        $peer->release($replacement);
    }
});
