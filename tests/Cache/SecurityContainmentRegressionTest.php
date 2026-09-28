<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cache\Adapter\ArrayCacheAdapter;
use Infocyph\CacheLayer\Cache\Adapter\SharedMemoryCacheAdapter;
use Infocyph\CacheLayer\Cache\Adapter\TieredCacheAdapter;
use Infocyph\CacheLayer\Cache\Adapter\PdoCacheAdapter;
use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\CacheLayer\Cache\CacheOptions;
use Infocyph\CacheLayer\Cache\Lock\FileLockProvider;
use Infocyph\CacheLayer\Cache\Tiering\TieredPoolFactory;
use Infocyph\CacheLayer\Serializer\ClosureSerializer;
use Infocyph\CacheLayer\Support\RedisConnection;
use Infocyph\CacheLayer\Counter\AtomicCounters;
use Infocyph\CacheLayer\Node\Adapter\NodeCacheAdapter;
use Infocyph\CacheLayer\Node\Adapter\NodeSqliteCacheAdapter;
use Infocyph\CacheLayer\Node\Connection\NodeSqliteConnection;
use Infocyph\CacheLayer\Node\Exception\NodeCacheConfigurationException;
use Infocyph\CacheLayer\Node\Exception\NodeCacheStorageException;
use Infocyph\CacheLayer\Node\NodeCacheConfig;
use Infocyph\CacheLayer\Serializer\SignedClosureSerializer;

test('adapter policy is immutable from the first facade binding', function () {
    $adapter = new ArrayCacheAdapter('shared-policy');
    new Cache($adapter, options: new CacheOptions(allowObjects: false, integrityKey: 'first-secret'));

    expect(fn() => new Cache(
        $adapter,
        options: new CacheOptions(allowObjects: true, integrityKey: 'second-secret'),
    ))->toThrow(LogicException::class);

    expect(fn() => new Cache(
        $adapter,
        options: new CacheOptions(allowObjects: false, integrityKey: 'first-secret'),
    ))->not->toThrow(LogicException::class);
});

test('node SQLite cache preserves caller-owned transactions', function () {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE business_rows (id INTEGER PRIMARY KEY, value TEXT NOT NULL)');

    $adapter = new NodeSqliteCacheAdapter($pdo, 'node-transaction');
    $item = $adapter->createItem('cache-key')->set('cache-value');

    $pdo->beginTransaction();
    $pdo->exec("INSERT INTO business_rows (value) VALUES ('business-value')");

    expect(fn() => $adapter->saveMany([$item]))
        ->toThrow(NodeCacheStorageException::class)
        ->and($pdo->inTransaction())->toBeTrue()
        ->and((int) $pdo->query('SELECT COUNT(*) FROM business_rows')->fetchColumn())->toBe(1);

    $pdo->rollBack();
});

test('filesystem adapters reject a symlinked namespace root', function () {
    foreach ([
        'file' => static fn(string $namespace, string $base): Cache => Cache::file($namespace, $base),
        'php-files' => static fn(string $namespace, string $base): Cache => Cache::phpFiles($namespace, $base),
    ] as $label => $factory) {
        $base = sys_get_temp_dir() . '/cachelayer-symlink-' . $label . '-' . bin2hex(random_bytes(4));
        $target = sys_get_temp_dir() . '/cachelayer-symlink-target-' . $label . '-' . bin2hex(random_bytes(4));
        mkdir($base, 0700, true);
        mkdir($target, 0700, true);
        $link = $base . DIRECTORY_SEPARATOR . 'cache_attacker';
        expect(symlink($target, $link))->toBeTrue();

        try {
            expect(fn() => $factory('attacker', $base))->toThrow(RuntimeException::class);
        } finally {
            if (is_link($link)) {
                unlink($link);
            }
            if (is_dir($target)) {
                rmdir($target);
            }
            if (is_dir($base)) {
                rmdir($base);
            }
        }
    }
});

test('Redis DSN errors never echo credentials', function () {
    $secret = 'audit-password';
    $dsn = 'redis://user:' . $secret . '@localhost/not-a-database';

    $previous = ini_set('zend.exception_ignore_args', '0');

    try {
        RedisConnection::connect($dsn);
        test()->fail('Expected invalid Redis DSN.');
    } catch (Throwable $failure) {
        for ($current = $failure; $current instanceof Throwable; $current = $current->getPrevious()) {
            expect($current->getMessage())->not->toContain($secret)
                ->and($current->getMessage())->not->toContain($dsn);
        }
        expect((string) $failure)->not->toContain($secret)
            ->and((string) $failure)->not->toContain($dsn);
    } finally {
        if ($previous !== false) {
            ini_set('zend.exception_ignore_args', $previous);
        }
    }
});

test('secret-bearing public parameters are marked sensitive', function () {
    $parameters = [
        [CacheOptions::class, '__construct', 'integrityKey'],
        [Cache::class, 'redis', 'dsn'],
        [Cache::class, 'valkey', 'dsn'],
        [Cache::class, 'mongodb', 'uri'],
        [Cache::class, 'pdo', 'dsn'],
        [Cache::class, 'pdo', 'password'],
        [Cache::class, 'tiered', 'tiers'],
        [PdoCacheAdapter::class, '__construct', 'dsn'],
        [PdoCacheAdapter::class, '__construct', 'password'],
        [AtomicCounters::class, 'redis', 'dsn'],
        [AtomicCounters::class, 'valkey', 'dsn'],
        [SignedClosureSerializer::class, '__construct', 'key'],
        [ClosureSerializer::class, 'signed', 'key'],
        [RedisConnection::class, 'connect', 'dsn'],
        [TieredPoolFactory::class, 'fromArray', 'tiers'],
    ];

    foreach ($parameters as [$class, $method, $parameter]) {
        $reflection = new ReflectionParameter([$class, $method], $parameter);
        expect($reflection->getAttributes(SensitiveParameter::class))->not->toBeEmpty();
    }
});


test('recursive payloads fail within bounded subprocess resources', function () {
    if (!function_exists('proc_open')) {
        test()->markTestSkipped('proc_open is required for the bounded recursion regression.');
    }

    $autoload = realpath(__DIR__ . '/../../vendor/autoload.php');
    expect($autoload)->not->toBeFalse();

    $script = tempnam(sys_get_temp_dir(), 'cachelayer-recursion-');
    expect($script)->not->toBeFalse();

    $code = <<<'PHP'
<?php
require %s;

use Infocyph\CacheLayer\Cache\Adapter\CachePayloadCodec;
use Infocyph\CacheLayer\Cache\CacheOptions;

$codec = new CachePayloadCodec(new CacheOptions(
    integrityKey: 'bounded-secret',
    maxPayloadBytes: 4096,
    allowClosures: false,
    allowObjects: false,
));

$direct = [];
$direct['self'] =& $direct;

$mutualA = [];
$mutualB = [];
$mutualA['b'] =& $mutualB;
$mutualB['a'] =& $mutualA;

foreach ([$direct, $mutualA] as $value) {
    try {
        $codec->encode($value, null);
        exit(10);
    } catch (InvalidArgumentException) {
    }
}

$record = [
    'format' => 2,
    'encoding' => 'native',
    'value' => &$direct,
    'expires' => null,
    'tags' => [],
    'namespace' => null,
];
$serialized = serialize($record);
$plain = 'cl2:' . $serialized;
$signed = 'cl2-sig:' . hash_hmac('sha256', $plain, 'bounded-secret') . ':' . $plain;
$compressed = 'cl2-gz:' . base64_encode(gzencode($serialized));

if ($codec->decode($signed) !== null) {
    exit(11);
}

$unsigned = new CachePayloadCodec(new CacheOptions(
    maxPayloadBytes: 4096,
    allowClosures: false,
    allowObjects: false,
));
if ($unsigned->decode($plain) !== null || $unsigned->decode($compressed) !== null) {
    exit(12);
}

$deep = 'leaf';
for ($index = 0; $index < 256; ++$index) {
    $deep = [$deep];
}
try {
    $unsigned->encode($deep, null);
    exit(13);
} catch (InvalidArgumentException) {
}

exit(0);
PHP;

    file_put_contents($script, sprintf($code, var_export($autoload, true)));
    $command = escapeshellarg(PHP_BINARY)
        . ' -d memory_limit=32M -d max_execution_time=3 '
        . escapeshellarg($script);
    exec($command, $output, $status);
    unlink($script);

    expect($status)->toBe(0);
});

test('file atomic consumption reports deletion failure instead of returning the value', function () {
    if (DIRECTORY_SEPARATOR === '\\') {
        test()->markTestSkipped('POSIX permission semantics are required for this regression.');
    }

    foreach ([
        'file' => static fn(string $base, CacheOptions $options): Cache
            => Cache::file('consume', $base, $options),
        'php-files' => static fn(string $base, CacheOptions $options): Cache
            => Cache::phpFiles('consume', $base, $options),
    ] as $label => $factory) {
        $base = sys_get_temp_dir() . '/cachelayer-consume-' . $label . '-' . bin2hex(random_bytes(4));
        $strict = $factory($base, new CacheOptions(failOpen: false));
        expect($strict->set('token', 'usable'))->toBeTrue();

        $data = $base . DIRECTORY_SEPARATOR . 'cache_consume' . DIRECTORY_SEPARATOR . 'data';
        expect(chmod($data, 0500))->toBeTrue();

        try {
            expect(fn() => $strict->atomic()?->getAndDelete('token', 'missing'))
                ->toThrow(\Infocyph\CacheLayer\Exceptions\CacheBackendException::class);
        } finally {
            chmod($data, 0700);
        }

        expect($strict->get('token'))->toBe('usable');
        $strict->clear();

        $open = $factory($base, new CacheOptions(failOpen: true));
        expect($open->set('token', 'usable'))->toBeTrue();
        expect(chmod($data, 0500))->toBeTrue();

        try {
            expect($open->atomic()?->getAndDelete('token', 'missing'))->toBe('missing');
        } finally {
            chmod($data, 0700);
        }

        expect($open->get('token'))->toBe('usable');
        $open->clear();

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($base);
    }
});


test('composite adapters preflight policy conflicts without partial binding', function () {
    $strict = new CacheOptions(allowObjects: false, allowClosures: false, integrityKey: 'strict');
    $permissive = new CacheOptions(allowObjects: true, allowClosures: true);

    $tierFirst = new ArrayCacheAdapter('tier-first');
    $tierConflict = new ArrayCacheAdapter('tier-conflict');
    new Cache($tierConflict, options: $strict);

    $tiered = new TieredCacheAdapter([$tierFirst, $tierConflict]);
    expect(fn() => new Cache($tiered, options: $permissive))
        ->toThrow(LogicException::class)
        ->and(fn() => new Cache($tierFirst, options: $strict))
        ->not->toThrow(LogicException::class);

    $connection = new PDO('sqlite::memory:');
    $l1 = new ArrayCacheAdapter('node-policy');
    $l2 = new NodeSqliteCacheAdapter($connection, 'node-policy');
    new Cache($l1, options: $strict);

    $node = new NodeCacheAdapter($l1, $l2, false);
    expect(fn() => new Cache($node, options: $permissive))
        ->toThrow(LogicException::class)
        ->and(fn() => new Cache($l2, options: $strict))
        ->not->toThrow(LogicException::class);
});

test('SQLite and file-lock owners reject symlinked path components', function () {
    if (DIRECTORY_SEPARATOR === '\\' || !function_exists('symlink')) {
        test()->markTestSkipped('POSIX symlink semantics are required for this regression.');
    }

    $base = sys_get_temp_dir() . '/cachelayer-path-trust-' . bin2hex(random_bytes(4));
    $target = $base . '/target';
    $link = $base . '/linked';
    mkdir($target, 0700, true);
    expect(symlink($target, $link))->toBeTrue();

    try {
        $config = new NodeCacheConfig(
            sqliteFile: $link . '/node.sqlite',
            namespace: 'path-trust',
            apcuEnabled: false,
        );
        expect(fn() => NodeSqliteConnection::create($config))
            ->toThrow(NodeCacheConfigurationException::class)
            ->and(fn() => Cache::sqlite('path-trust', $link . '/pdo.sqlite'))
            ->toThrow(RuntimeException::class);

        $locks = $base . '/locks';
        mkdir($locks, 0700);
        $targetFile = $base . '/lock-target';
        touch($targetFile);
        $lockPath = $locks . DIRECTORY_SEPARATOR . hash('xxh128', 'claim') . '.lock';
        expect(symlink($targetFile, $lockPath))->toBeTrue()
            ->and((new FileLockProvider($locks))->acquire('claim', 0.0))->toBeNull();
    } finally {
        if (is_link($base . '/locks/' . hash('xxh128', 'claim') . '.lock')) {
            unlink($base . '/locks/' . hash('xxh128', 'claim') . '.lock');
        }
        if (is_link($link)) {
            unlink($link);
        }
        foreach ([$base . '/lock-target', $base . '/locks'] as $path) {
            is_dir($path) ? rmdir($path) : (is_file($path) ? unlink($path) : null);
        }
        if (is_dir($target)) {
            rmdir($target);
        }
        if (is_dir($base)) {
            rmdir($base);
        }
    }
});

test('shared-memory token creation rejects a pre-created symlink', function () {
    if (DIRECTORY_SEPARATOR === '\\' || !function_exists('symlink') || !function_exists('shm_attach')) {
        test()->markTestSkipped('Shared-memory and POSIX symlink support are required.');
    }

    $namespace = 'token-' . bin2hex(random_bytes(4));
    $directory = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR . 'cachelayer' . DIRECTORY_SEPARATOR . 'shared-memory';
    if (!is_dir($directory)) {
        mkdir($directory, 0700, true);
    }
    $target = tempnam(sys_get_temp_dir(), 'cachelayer-token-target-');
    expect($target)->not->toBeFalse();
    $token = $directory . DIRECTORY_SEPARATOR . hash('xxh128', $namespace) . '.tok';
    expect(symlink($target, $token))->toBeTrue();

    try {
        expect(fn() => new SharedMemoryCacheAdapter($namespace))
            ->toThrow(RuntimeException::class);
    } finally {
        if (is_link($token)) {
            unlink($token);
        }
        if (is_string($target) && is_file($target)) {
            unlink($target);
        }
    }
});
