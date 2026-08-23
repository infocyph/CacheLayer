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

        expect($handle)->not->toBeNull()
            ->and($handle?->leaseSeconds)->toBe(0.05)
            ->and($first->refresh($handle, 0.05))->toBeTrue();

        usleep(75_000);

        expect($second->acquire('worker:reports', 0.0, 0.05))->toBeNull();

        $first->release($handle);

        $replacement = $second->acquire('worker:reports', 0.0, 0.05);
        expect($replacement)->not->toBeNull();
        $second->release($replacement);
    } finally {
        foreach (glob($directory . '/*.lock') ?: [] as $file) {
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
            foreach (glob($directory . '/*.lock') ?: [] as $file) {
                unlink($file);
            }
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }
});

test('sqlite PDO locks use the shared file-lock fallback', function (): void {
    if (!extension_loaded('pdo_sqlite')) {
        test()->markTestSkipped('pdo_sqlite is not available.');
    }

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
        foreach (glob($directory . '/*.lock') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($directory)) {
            rmdir($directory);
        }
    }
});

test('sqlite PDO locks use the default file-lock fallback', function (): void {
    if (!extension_loaded('pdo_sqlite')) {
        test()->markTestSkipped('pdo_sqlite is not available.');
    }

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
    if (!extension_loaded('pdo_sqlite')) {
        test()->markTestSkipped('pdo_sqlite is not available.');
    }

    expect(fn(): PdoLockProvider => PdoLockProvider::strict(new PDO('sqlite::memory:')))
        ->toThrow(UnsupportedPdoLockDriver::class);
});

test('strict PDO locks reject unsupported drivers during construction', function (): void {
    $pdo = new class () extends PDO {
        public function __construct()
        {
        }

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

            public function getAttribute(int $attribute): mixed
            {
                unset($attribute);

                return $this->driver;
            }
        };

        $provider = PdoLockProvider::strict($pdo);
        $fallback = (new ReflectionObject($provider))->getProperty('fallback')->getValue($provider);

        expect($provider)->toBeInstanceOf(PdoLockProvider::class)
            ->and($fallback)->toBeNull();
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
        public function acquire(string $key, float $waitSeconds, float $leaseSeconds = 30.0): ?LockHandle
        {
            unset($key, $waitSeconds, $leaseSeconds);

            return null;
        }

        public function refresh(?LockHandle $handle, float $leaseSeconds): bool
        {
            unset($handle, $leaseSeconds);

            return false;
        }

        public function release(?LockHandle $handle): void
        {
            unset($handle);
        }
    };
    $pdo = new class () extends PDO {
        public function __construct()
        {
        }

        public function getAttribute(int $attribute): mixed
        {
            unset($attribute);

            return 'sqlite';
        }
    };

    expect(new PdoLockProvider($pdo, fallback: $fallback))->toBeInstanceOf(PdoLockProvider::class);
});

test('MySQL PDO locks use the native lock path', function (): void {
    $pdo = new class () extends PDO {
        public function __construct()
        {
        }

        public function getAttribute(int $attribute): mixed
        {
            unset($attribute);

            return 'mysql';
        }

        public function prepare(string $query, array $options = []): PDOStatement|false
        {
            unset($query, $options);

            return $this->successfulStatement();
        }

        public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
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

                public function execute(?array $params = null): bool
                {
                    unset($params);

                    return true;
                }

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

        public function getAttribute(int $attribute): mixed
        {
            unset($attribute);

            return 'pgsql';
        }

        public function prepare(string $query, array $options = []): PDOStatement|false
        {
            unset($query, $options);

            return $this->successfulStatement();
        }

        public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
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

                public function execute(?array $params = null): bool
                {
                    unset($params);

                    return true;
                }

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
