<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cache\Lock;

use Throwable;

final class PdoLockProvider implements LockProviderInterface
{
    use GeneratesLockTokens;

    /** @var array<string, string> */
    private array $activeTokens = [];

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

    public function acquire(string $key, float $waitSeconds, float $leaseSeconds = 30.0): ?LockHandle
    {
        if ($leaseSeconds <= 0) {
            throw new \InvalidArgumentException('Lock lease duration must be positive.');
        }

        return match ($this->driver) {
            'mysql', 'mariadb' => $this->acquireMysql($key, $waitSeconds, $leaseSeconds),
            'pgsql' => $this->acquirePgsql($key, $waitSeconds, $leaseSeconds),
            default => $this->fallback->acquire($key, $waitSeconds, $leaseSeconds),
        };
    }

    public function refresh(?LockHandle $handle, float $leaseSeconds): bool
    {
        if ($leaseSeconds <= 0) {
            throw new \InvalidArgumentException('Lock lease duration must be positive.');
        }
        if (!$handle instanceof LockHandle) {
            return false;
        }

        return match ($this->driver) {
            'mysql', 'mariadb', 'pgsql' => $this->owns($handle) && $this->connectionAlive(),
            default => $this->fallback->refresh($handle, $leaseSeconds),
        };
    }

    public function release(?LockHandle $handle): void
    {
        if (!$handle instanceof LockHandle) {
            return;
        }

        if (!in_array($this->driver, ['mysql', 'mariadb', 'pgsql'], true)) {
            $this->fallback->release($handle);

            return;
        }
        if (!$this->owns($handle)) {
            return;
        }

        $released = match ($this->driver) {
            'mysql', 'mariadb' => $this->releaseMysql($handle),
            'pgsql' => $this->releasePgsql($handle),
        };
        if ($released) {
            unset($this->activeTokens[$handle->key]);
        }
    }

    /** @return array{int, int} */
    private static function advisoryKeys(string $value): array
    {
        $digest = hash('sha256', $value);

        return [
            self::signedHex32(substr($digest, 0, 8)),
            self::signedHex32(substr($digest, 8, 8)),
        ];
    }

    private static function isDatabaseTrue(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 't';
    }

    private static function signedHex32(string $value): int
    {
        $unsigned = (int) hexdec($value);

        return $unsigned > 0x7FFFFFFF ? $unsigned - 0x100000000 : $unsigned;
    }

    private function acquireMysql(string $key, float $waitSeconds, float $leaseSeconds): ?LockHandle
    {
        $deadline = microtime(true) + max(0.0, $waitSeconds);
        $lockKey = self::digestLockKey($this->prefix . $key);
        if (isset($this->activeTokens[$lockKey])) {
            return null;
        }
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
                    $this->activeTokens[$lockKey] = $token;

                    return new LockHandle($lockKey, $token, leaseSeconds: $leaseSeconds);
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

    private function acquirePgsql(string $key, float $waitSeconds, float $leaseSeconds): ?LockHandle
    {
        $deadline = microtime(true) + max(0.0, $waitSeconds);
        $lockKey = self::digestLockKey($this->prefix . $key);
        if (isset($this->activeTokens[$lockKey])) {
            return null;
        }
        $advisoryKeys = self::advisoryKeys($lockKey);
        $token = self::generateToken();
        if ($token === null) {
            return null;
        }

        do {
            try {
                $stmt = $this->pdo->prepare('SELECT pg_try_advisory_lock(:k1, :k2)');
                $stmt->execute([':k1' => $advisoryKeys[0], ':k2' => $advisoryKeys[1]]);
                $result = $stmt->fetchColumn();
                if (self::isDatabaseTrue($result)) {
                    $this->activeTokens[$lockKey] = $token;

                    return new LockHandle($lockKey, $token, $advisoryKeys, $leaseSeconds);
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

    private function connectionAlive(): bool
    {
        try {
            return $this->pdo->query('SELECT 1') !== false;
        } catch (Throwable) {
            return false;
        }
    }

    private function owns(LockHandle $handle): bool
    {
        $token = $this->activeTokens[$handle->key] ?? null;

        return is_string($token) && hash_equals($token, $handle->token);
    }

    private function releaseMysql(LockHandle $handle): bool
    {
        try {
            $stmt = $this->pdo->prepare('SELECT RELEASE_LOCK(:k)');
            $stmt->execute([':k' => $handle->key]);
            $result = $stmt->fetchColumn();

            return $result === 1 || $result === '1';
        } catch (Throwable) {
            return false;
        }
    }

    private function releasePgsql(LockHandle $handle): bool
    {
        $advisoryKeys = is_array($handle->resource)
            ? $handle->resource
            : self::advisoryKeys($handle->key);

        try {
            $stmt = $this->pdo->prepare('SELECT pg_advisory_unlock(:k1, :k2)');
            $stmt->execute([':k1' => $advisoryKeys[0], ':k2' => $advisoryKeys[1]]);
            $result = $stmt->fetchColumn();

            return self::isDatabaseTrue($result);
        } catch (Throwable) {
            return false;
        }
    }
}
