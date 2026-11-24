<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cache\Lock\FileLockProvider;
use Infocyph\CacheLayer\Cache\Lock\LockHandle;
use Infocyph\CacheLayer\Cache\Lock\LockProviderInterface;
use Infocyph\CacheLayer\Cache\Lock\PdoLockProvider;
use Infocyph\CacheLayer\Cache\Lock\UnsupportedPdoLockDriver;

test('file locks retain ownership until release and support lease refresh', function (): void {
    $directory = sys_get_temp_dir() . '/cachelayer-lock-' . bin2hex(random_bytes(5));
    $first = new FileLockProvider($directory);
    $second = new FileLockProvider($directory);

    try {
        $handle = $first->acquire('worker:reports', 0.0, 0.05);

        $handle ??= throw new RuntimeException('Unable to acquire file lock.');
        expect($handle->leaseSeconds)->toBe(0.05)
            ->and($first->refresh($handle, 0.05))->toBeTrue();

        usleep(75_000);

        expect($second->acquire('worker:reports', 0.0, 0.05))->toBeNull();

        $first->release($handle);

        $replacement = $second->acquire('worker:reports', 0.0, 0.05);
        expect($replacement)->not->toBeNull();
        $second->release($replacement);
    } finally {
        $files = glob($directory . '/*.lock');
        if ($files === false) {
            throw new RuntimeException('Unable to enumerate cache test files.');
        }
        foreach ($files as $file) {
            unlink($file);
        }
        if (is_dir($directory)) {
            rmdir($directory);
        }
    }
});

test('lock providers reject non-positive lease durations', function (): void {
    $provider = new FileLockProvider();

    expect(fn() => $provider->acquire('invalid', 0.0, 0.0))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn() => $provider->refresh(null, 0.0))
        ->toThrow(InvalidArgumentException::class);
});

test('file lock registry keeps identical keys in different directories independent', function (): void {
    $firstDirectory = sys_get_temp_dir() . '/cachelayer-lock-a-' . bin2hex(random_bytes(5));
    $secondDirectory = sys_get_temp_dir() . '/cachelayer-lock-b-' . bin2hex(random_bytes(5));
    $first = new FileLockProvider($firstDirectory);
    $second = new FileLockProvider($secondDirectory);

    try {
        $firstHandle = $first->acquire('shared-key', 0.0, 1.0);
        $secondHandle = $second->acquire('shared-key', 0.0, 1.0);

        expect($firstHandle)->not->toBeNull()
            ->and($secondHandle)->not->toBeNull();

        $first->release($firstHandle);
        $second->release($secondHandle);
    } finally {
        foreach ([$firstDirectory, $secondDirectory] as $directory) {
            $files = glob($directory . '/*.lock');
            if ($files === false) {
                throw new RuntimeException('Unable to enumerate cache test files.');
            }
            foreach ($files as $file) {
                unlink($file);
            }
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }
});

test('sqlite PDO locks use the shared file-lock fallback', function (): void {
    expect(extension_loaded('pdo_sqlite'))->toBeTrue();

    $directory = sys_get_temp_dir() . '/cachelayer-pdo-lock-' . bin2hex(random_bytes(5));
    $pdo = new PDO('sqlite::memory:');
    $first = new PdoLockProvider($pdo, fallback: new FileLockProvider($directory));
    $second = new PdoLockProvider($pdo, fallback: new FileLockProvider($directory));

    try {
        $handle = $first->acquire('worker:imports', 0.0, 10.0);

        expect($handle)->not->toBeNull()
            ->and($first->refresh($handle, 10.0))->toBeTrue()
            ->and($second->acquire('worker:imports', 0.0, 10.0))->toBeNull();

        $first->release($handle);

        $replacement = $second->acquire('worker:imports', 0.0, 10.0);
        expect($replacement)->not->toBeNull();
        $second->release($replacement);
    } finally {
        $files = glob($directory . '/*.lock');
        if ($files === false) {
            throw new RuntimeException('Unable to enumerate cache test files.');
        }
        foreach ($files as $file) {
            unlink($file);
        }
        if (is_dir($directory)) {
            rmdir($directory);
        }
    }
});

test('sqlite PDO locks use the default file-lock fallback', function (): void {
    expect(extension_loaded('pdo_sqlite'))->toBeTrue();

    $key = 'worker:default-fallback:' . bin2hex(random_bytes(5));
    $pdo = new PDO('sqlite::memory:');
    $first = new PdoLockProvider($pdo);
    $second = new PdoLockProvider($pdo);

    $handle = $first->acquire($key, 0.0, 10.0);

    expect($handle)->not->toBeNull()
        ->and($second->acquire($key, 0.0, 10.0))->toBeNull();

    $first->release($handle);
});

test('strict PDO locks reject SQLite during construction', function (): void {
    expect(extension_loaded('pdo_sqlite'))->toBeTrue();

    expect(fn(): PdoLockProvider => PdoLockProvider::strict(new PDO('sqlite::memory:')))
        ->toThrow(UnsupportedPdoLockDriver::class);
});

test('strict PDO locks reject unsupported drivers during construction', function (): void {
    $pdo = new class () extends PDO {
        public function __construct()
        {
        }

        #[\Override]
        public function getAttribute(int $attribute): mixed
        {
            unset($attribute);

            return 'oci';
        }
    };

    expect(fn(): PdoLockProvider => PdoLockProvider::strict($pdo))
        ->toThrow(UnsupportedPdoLockDriver::class);
});

test('strict PDO locks allow native drivers without a fallback', function (): void {
    foreach (['mysql', 'mariadb', 'pgsql'] as $driver) {
        $pdo = new class ($driver) extends PDO {
            public function __construct(private string $driver)
            {
            }

            #[\Override]
            public function getAttribute(int $attribute): mixed
            {
                unset($attribute);

                return $this->driver;
            }
        };

        $provider = PdoLockProvider::strict($pdo);
        $fallback = (new ReflectionObject($provider))->getProperty('fallback')->getValue($provider);

        expect($fallback)->toBeNull();
    }
});

test('PDO lock providers report their native driver capability', function (): void {
    expect(PdoLockProvider::supportsNativeDriver('mysql'))->toBeTrue()
        ->and(PdoLockProvider::supportsNativeDriver('MARIADB'))->toBeTrue()
        ->and(PdoLockProvider::supportsNativeDriver('pgsql'))->toBeTrue()
        ->and(PdoLockProvider::supportsNativeDriver('sqlite'))->toBeFalse()
        ->and(PdoLockProvider::supportsNativeDriver('oci'))->toBeFalse();
});

test('PDO lock providers accept an explicit non-file fallback', function (): void {
    $fallback = new class implements LockProviderInterface {
        #[\Override]
        public function acquire(string $key, float $waitSeconds, float $leaseSeconds = 30.0): ?LockHandle
        {
            unset($key, $waitSeconds, $leaseSeconds);

            return null;
        }

        #[\Override]
        public function refresh(?LockHandle $handle, float $leaseSeconds): bool
        {
            unset($handle, $leaseSeconds);

            return false;
        }

        #[\Override]
        public function release(?LockHandle $handle): void
        {
            unset($handle);
        }
    };
    $pdo = new class () extends PDO {
        public function __construct()
        {
        }

        #[\Override]
        public function getAttribute(int $attribute): mixed
        {
            unset($attribute);

            return 'sqlite';
        }
    };

    $provider = new PdoLockProvider($pdo, fallback: $fallback);
    expect((new ReflectionObject($provider))->getProperty('fallback')->getValue($provider))->toBe($fallback);
});

test('MySQL PDO locks use the native lock path', function (): void {
    $pdo = new class () extends PDO {
        public function __construct()
        {
        }

        #[\Override]
        public function getAttribute(int $attribute): mixed
        {
            unset($attribute);

            return 'mysql';
        }

        /** @param array<int,mixed> $options */
        #[\Override]
        public function prepare(string $query, array $options = []): PDOStatement
        {
            unset($query, $options);

            return $this->successfulStatement();
        }

        #[\Override]
        public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement
        {
            unset($query, $fetchMode, $fetchModeArgs);

            return $this->successfulStatement();
        }

        private function successfulStatement(): PDOStatement
        {
            return new class () extends PDOStatement {
                public function __construct()
                {
                }

                /** @param array<array-key,mixed>|null $params */
                #[\Override]
                public function execute(?array $params = null): bool
                {
                    unset($params);

                    return true;
                }

                #[\Override]
                public function fetchColumn(int $column = 0): mixed
                {
                    unset($column);

                    return '1';
                }
            };
        }
    };
    $provider = PdoLockProvider::strict($pdo);
    $handle = $provider->acquire('worker:mysql', 0.0, 10.0);

    expect($handle)->not->toBeNull()
        ->and($provider->refresh($handle, 10.0))->toBeTrue();

    $provider->release($handle);

    expect($provider->refresh($handle, 10.0))->toBeFalse();
});

test('PostgreSQL PDO locks accept native boolean results', function (): void {
    $pdo = new class () extends PDO {
        public function __construct()
        {
        }

        #[\Override]
        public function getAttribute(int $attribute): mixed
        {
            unset($attribute);

            return 'pgsql';
        }

        /** @param array<int,mixed> $options */
        #[\Override]
        public function prepare(string $query, array $options = []): PDOStatement
        {
            unset($query, $options);

            return $this->successfulStatement();
        }

        #[\Override]
        public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement
        {
            unset($query, $fetchMode, $fetchModeArgs);

            return $this->successfulStatement();
        }

        private function successfulStatement(): PDOStatement
        {
            return new class () extends PDOStatement {
                public function __construct()
                {
                }

                /** @param array<array-key,mixed>|null $params */
                #[\Override]
                public function execute(?array $params = null): bool
                {
                    unset($params);

                    return true;
                }

                #[\Override]
                public function fetchColumn(int $column = 0): mixed
                {
                    unset($column);

                    return true;
                }
            };
        }
    };
    $provider = new PdoLockProvider($pdo);
    $handle = $provider->acquire('worker:postgres', 0.0, 10.0);

    expect($handle)->not->toBeNull()
        ->and($provider->refresh($handle, 10.0))->toBeTrue();

    $provider->release($handle);

    expect($provider->refresh($handle, 10.0))->toBeFalse();
});

test('lock providers reject nonfinite waits and leases before backend operations', function (): void {
    $providers = [
        new FileLockProvider(),
        new PdoLockProvider(new PDO('sqlite::memory:')),
        new \Infocyph\CacheLayer\Cache\Lock\RedisLockProvider(new Redis()),
        new \Infocyph\CacheLayer\Cache\Lock\MemcachedLockProvider(new Memcached()),
    ];
    foreach ($providers as $provider) {
        expect(fn() => $provider->acquire('invalid', INF, 1.0))->toThrow(InvalidArgumentException::class)
            ->and(fn() => $provider->acquire('invalid', 0.0, NAN))->toThrow(InvalidArgumentException::class)
            ->and(fn() => $provider->acquire('invalid', 0.0, INF))->toThrow(InvalidArgumentException::class);
    }
});

test('a stale file lock handle cannot release its replacement', function (): void {
    $directory = sys_get_temp_dir() . '/cachelayer-lock-stale-' . bin2hex(random_bytes(5));
    $provider = new FileLockProvider($directory);
    $first = null;
    $second = null;
    try {
        $first = $provider->acquire('shared', 0.0);
        expect($first)->not->toBeNull();
        $provider->release($first);
        $second = $provider->acquire('shared', 0.0);
        expect($second)->not->toBeNull();
        $provider->release($first);
        expect($provider->refresh($first, 30.0))->toBeFalse()
            ->and($provider->refresh($second, 30.0))->toBeTrue()
            ->and($provider->acquire('shared', 0.0))->toBeNull();
    } finally {
        $provider->release($first);
        $provider->release($second);
        if (is_dir($directory)) {
            foreach (new DirectoryIterator($directory) as $file) {
                if ($file->isFile()) {
                    unlink($file->getPathname());
                }
            }
            rmdir($directory);
        }
    }
});
