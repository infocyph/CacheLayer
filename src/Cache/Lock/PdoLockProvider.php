<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cache\Lock;

use Throwable;

final readonly class PdoLockProvider implements LockProviderInterface
{
    use GeneratesLockTokens;

    private string $driver;

    private int $retrySleepMicros;

    public function __construct(
        private \PDO $pdo,
        private string $prefix = 'cachelayer:lock:',
        int $retrySleepMicros = 50_000,
        private FileLockProvider $fallback = new FileLockProvider(),
    ) {
        $this->retrySleepMicros = max(1_000, $retrySleepMicros);
        $driver = $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $this->driver = is_string($driver) ? $driver : '';
    }

    public function acquire(string $key, float $waitSeconds): ?LockHandle
    {
        return match ($this->driver) {
            'mysql', 'mariadb' => $this->acquireMysql($key, $waitSeconds),
            'pgsql' => $this->acquirePgsql($key, $waitSeconds),
            default => $this->fallback->acquire($key, $waitSeconds),
        };
    }

    public function release(?LockHandle $handle): void
    {
        if (!$handle instanceof LockHandle) {
            return;
        }

        match ($this->driver) {
            'mysql', 'mariadb' => $this->releaseMysql($handle),
            'pgsql' => $this->releasePgsql($handle),
            default => $this->fallback->release($handle),
        };
    }

    private static function signedCrc32(string $value): int
    {
        $u = crc32($value);

        return $u > 0x7FFFFFFF ? $u - 0x100000000 : $u;
    }

    private function acquireMysql(string $key, float $waitSeconds): ?LockHandle
    {
        $deadline = microtime(true) + max(0.0, $waitSeconds);
        $lockKey = $this->prefix . self::digestLockKey($key);
        $token = self::generateToken();
        if ($token === null) {
            return null;
        }

        do {
            try {
                $stmt = $this->pdo->prepare('SELECT GET_LOCK(:k, 0)');
                $stmt->execute([':k' => $lockKey]);
                $result = $stmt->fetchColumn();
                if ((string) $result === '1') {
                    return new LockHandle($lockKey, $token);
                }
            } catch (Throwable) {
                return null;
            }

            if (microtime(true) >= $deadline) {
                return null;
            }

            usleep($this->retrySleepMicros);
        } while (true);
    }

    private function acquirePgsql(string $key, float $waitSeconds): ?LockHandle
    {
        $deadline = microtime(true) + max(0.0, $waitSeconds);
        $lockKey = $this->prefix . self::digestLockKey($key);
        $advisoryKey = self::signedCrc32($lockKey);
        $token = self::generateToken();
        if ($token === null) {
            return null;
        }

        do {
            try {
                $stmt = $this->pdo->prepare('SELECT pg_try_advisory_lock(:k)');
                $stmt->execute([':k' => $advisoryKey]);
                $result = $stmt->fetchColumn();
                if ($result === 1 || $result === 't' || $result === '1') {
                    return new LockHandle($lockKey, $token, $advisoryKey);
                }
            } catch (Throwable) {
                return null;
            }

            if (microtime(true) >= $deadline) {
                return null;
            }

            usleep($this->retrySleepMicros);
        } while (true);
    }

    private function releaseMysql(LockHandle $handle): void
    {
        try {
            $stmt = $this->pdo->prepare('SELECT RELEASE_LOCK(:k)');
            $stmt->execute([':k' => $handle->key]);
        } catch (Throwable) {
            // Best effort unlock.
        }
    }

    private function releasePgsql(LockHandle $handle): void
    {
        $advisoryKey = is_int($handle->resource)
            ? $handle->resource
            : self::signedCrc32($handle->key);

        try {
            $stmt = $this->pdo->prepare('SELECT pg_advisory_unlock(:k)');
            $stmt->execute([':k' => $advisoryKey]);
        } catch (Throwable) {
            // Best effort unlock.
        }
    }
}
