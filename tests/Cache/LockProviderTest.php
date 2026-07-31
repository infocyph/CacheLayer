<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cache\Lock\FileLockProvider;
use Infocyph\CacheLayer\Cache\Lock\PdoLockProvider;

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
