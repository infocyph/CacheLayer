<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\CacheLayer\Cache\CacheOptions;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

if (PHP_SAPI !== 'cli') {
    throw new RuntimeException('Cache race requires CLI execution.');
}

$input = stream_get_contents(STDIN);
if (!is_string($input)) {
    throw new RuntimeException('Missing cache race configuration.');
}
$configuration = json_decode($input, true, 512, JSON_THROW_ON_ERROR);
if (!is_array($configuration)
    || !is_string($configuration['backend'] ?? null)
    || !is_string($configuration['namespace'] ?? null)
    || !is_string($configuration['operation'] ?? null)
    || !is_int($configuration['worker'] ?? null)
    || $configuration['worker'] < 1) {
    throw new RuntimeException('Invalid cache race configuration.');
}

/** @var array{backend:string,namespace:string,operation:string,worker:int,dsn?:mixed,user?:mixed,password?:mixed,host?:mixed,port?:mixed,isolation?:mixed} $configuration */
$requireInteger = static function (mixed $value): int {
    if (!is_int($value)) {
        throw new RuntimeException('Unexpected cache race value type.');
    }

    return $value;
};

try {
    $options = new CacheOptions(failOpen: false);
    if ($configuration['backend'] === 'pdo') {
        $dsn = $configuration['dsn'] ?? null;
        $user = $configuration['user'] ?? null;
        $password = $configuration['password'] ?? null;
        if (!is_string($dsn) || !is_string($user) || !is_string($password)) {
            throw new RuntimeException('Invalid PDO race configuration.');
        }
        $namespace = match ($configuration['namespace']) {
            'sqlite-race' => 'sqlite-race',
            'schema-race' => 'schema-race',
            'mysql-insert-race' => 'mysql-insert-race',
            'pgsql-insert-race' => 'pgsql-insert-race',
            default => throw new RuntimeException('Unsupported PDO race namespace.'),
        };
        if ($namespace === 'pgsql-insert-race') {
            $pdo = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            if (($configuration['isolation'] ?? null) === 'serializable') {
                $pdo->exec('SET SESSION CHARACTERISTICS AS TRANSACTION ISOLATION LEVEL SERIALIZABLE');
            }
            $adapter = new \Infocyph\CacheLayer\Cache\Adapter\PdoCacheAdapter(
                $namespace, $dsn, pdo: $pdo, table: 'cachelayer_schema_race', initializeSchema: false,
            );
            $cache = new Cache($adapter, options: $options, namespace: $namespace);
        } else {
            $cache = Cache::pdo(
                $namespace,
                $dsn,
                $user,
                $password,
                table: $namespace === 'schema-race' && $configuration['worker'] % 2 === 0
                    ? 'CacheLayer_Schema_Race'
                    : 'cachelayer_schema_race',
                options: $options,
            );
        }
    } elseif ($configuration['backend'] === 'memcached') {
        $host = $configuration['host'] ?? null;
        $port = $configuration['port'] ?? null;
        if (!is_string($host) || !is_int($port)) {
            throw new RuntimeException('Invalid Memcached race configuration.');
        }
        $cache = Cache::memcached($configuration['namespace'], [[$host, $port, 0]], options: $options);
    } else {
        throw new RuntimeException('Unsupported cache race backend.');
    }

    $worker = $configuration['worker'];
    if ($configuration['operation'] === 'remember') {
        $result = $requireInteger($cache->remember('race', static function () use ($worker): int {
            usleep(150_000);

            return $worker;
        }, 600));
    } else {
        $atomic = $cache->atomic() ?? throw new RuntimeException('Missing atomic capability.');
        $result = match ($configuration['operation']) {
            'insert' => $atomic->setIfAbsent('race', $worker, 600) ? $worker : 0,
            'mixed' => $worker % 2 === 0
                ? ($atomic->compareAndSet('race', 1, 2, 600) ? 10 : 0)
                : $requireInteger($atomic->getAndDelete('race', 0)),
            default => throw new RuntimeException('Unsupported cache race operation.'),
        };
    }

    fwrite(STDOUT, (string) $result);
} catch (Throwable $failure) {
    // Do not include connection credentials in test diagnostics.
    fwrite(STDERR, $failure::class . ': ' . $failure->getCode() . ' at ' . basename($failure->getFile()) . ':' . $failure->getLine());
    throw new RuntimeException('Independent cache worker failed.');
}
