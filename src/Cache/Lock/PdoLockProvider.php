<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cache\Lock;

use Throwable;

final class PdoLockProvider implements LockProviderInterface
{
    use GeneratesLockTokens;

    private const string PGSQL_KEYS = 'WITH lock_keys AS (SELECT CAST(:k1 AS integer) AS first_key, CAST(:k2 AS integer) AS second_key) ';

    private const string PGSQL_OWNED = "EXISTS (SELECT 1 FROM pg_locks WHERE locktype = 'advisory' "
        . 'AND pid = pg_backend_pid() AND granted AND objsubid = 2 '
        . 'AND classid = CAST((CAST(first_key AS bigint) & 4294967295) AS oid) '
        . 'AND objid = CAST((CAST(second_key AS bigint) & 4294967295) AS oid))';

    /** @var array<string, string> */
    private array $activeTokens = [];

    private string $driver;

    private int $retrySleepMicros;

    /**
     * @throws UnsupportedPdoLockDriver
     */
    public function __construct(
        private \PDO $pdo,
        private string $prefix = 'cachelayer:lock:',
        int $retrySleepMicros = 50_000,
        private ?LockProviderInterface $fallback = new FileLockProvider(),
    ) {
        $this->retrySleepMicros = max(1_000, $retrySleepMicros);
        $driver = $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $this->driver = is_string($driver) ? strtolower($driver) : '';

        if ($this->fallback === null && !self::supportsNativeDriver($this->driver)) {
            throw new UnsupportedPdoLockDriver(sprintf(
                'PDO driver "%s" does not support native cache locks.',
                $this->driver === '' ? 'unknown' : $this->driver,
            ));
        }
    }

    /**
     * @throws UnsupportedPdoLockDriver
     */
    public static function strict(
        \PDO $pdo,
        string $prefix = 'cachelayer:lock:',
        int $retrySleepMicros = 50_000,
    ): self {
        return new self($pdo, $prefix, $retrySleepMicros, fallback: null);
    }

    public static function supportsNativeDriver(string $driver): bool
    {
        return in_array(strtolower($driver), ['mysql', 'mariadb', 'pgsql'], true);
    }

    /**
     * @throws \InvalidArgumentException
     */
    #[\Override]
    public function acquire(string $key, float $waitSeconds, float $leaseSeconds = 30.0): ?LockHandle
    {
        if (!is_finite($leaseSeconds) || $leaseSeconds <= 0.0) {
            throw new \InvalidArgumentException('Lock lease duration must be finite and positive.');
        }

        if (!is_finite($waitSeconds)) {
            throw new \InvalidArgumentException('Lock wait duration must be finite.');
        }

        return match ($this->driver) {
            'mysql', 'mariadb' => $this->acquireMysql($key, $waitSeconds, $leaseSeconds),
            'pgsql' => $this->acquirePgsql($key, $waitSeconds, $leaseSeconds),
            default => $this->fallback?->acquire($key, $waitSeconds, $leaseSeconds),
        };
    }

    /**
     * Reports the actual coordination provider selected at construction, not a
     * fallback to use after a native lock failure.
     */
    public function effectiveLockProvider(): LockProviderInterface
    {
        return self::supportsNativeDriver($this->driver) ? $this : ($this->fallback ?? $this);
    }

    /**
     * @throws \InvalidArgumentException
     */
    #[\Override]
    public function refresh(?LockHandle $handle, float $leaseSeconds): bool
    {
        if (!is_finite($leaseSeconds) || $leaseSeconds <= 0.0) {
            throw new \InvalidArgumentException('Lock lease duration must be finite and positive.');
        }
        if (!$handle instanceof LockHandle) {
            return false;
        }

        return match ($this->driver) {
            'mysql', 'mariadb', 'pgsql' => $this->owns($handle) && $this->refreshNative($handle),
            default => $this->fallback?->refresh($handle, $leaseSeconds) ?? false,
        };
    }

    #[\Override]
    public function release(?LockHandle $handle): void
    {
        if (!$handle instanceof LockHandle) {
            return;
        }

        if (!self::supportsNativeDriver($this->driver)) {
            $this->fallback?->release($handle);

            return;
        }
        if (!$this->owns($handle)) {
            return;
        }

        $released = match ($this->driver) {
            'mysql', 'mariadb' => $this->releaseMysql($handle),
            'pgsql' => $this->releasePgsql($handle),
            default => false,
        };
        if ($released) {
            unset($this->activeTokens[$handle->key]);
        }
    }

    /** @return array{int, int} */
    private static function advisoryKeys(string $value): array
    {
        $digest = hash('xxh128', $value);

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
                $stmt = $this->pdo->prepare('SELECT CASE WHEN IS_USED_LOCK(:owned) = CONNECTION_ID() THEN 0 ELSE GET_LOCK(:k, 0) END');
                $stmt->execute([':owned' => $lockKey, ':k' => $lockKey]);
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
                $stmt = $this->pdo->prepare(self::PGSQL_KEYS . 'SELECT CASE WHEN ' . self::PGSQL_OWNED
                    . ' THEN FALSE ELSE pg_try_advisory_lock(first_key, second_key) END FROM lock_keys');
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

    private function owns(LockHandle $handle): bool
    {
        $token = $this->activeTokens[$handle->key] ?? null;

        return is_string($token) && hash_equals($token, $handle->token);
    }

    private function refreshNative(LockHandle $handle): bool
    {
        try {
            if ($this->driver === 'pgsql') {
                $keys = self::advisoryKeys($handle->key);
                $stmt = $this->pdo->prepare(self::PGSQL_KEYS . 'SELECT ' . self::PGSQL_OWNED . ' FROM lock_keys');
                $stmt->execute([':k1' => $keys[0], ':k2' => $keys[1]]);
            } else {
                $stmt = $this->pdo->prepare('SELECT IS_USED_LOCK(:k) = CONNECTION_ID()');
                $stmt->execute([':k' => $handle->key]);
            }
            if (self::isDatabaseTrue($stmt->fetchColumn())) {
                return true;
            }
            unset($this->activeTokens[$handle->key]);
        } catch (Throwable) {
            // A failed ownership probe cannot establish ownership.
        }

        return false;
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
            $stmt = $this->pdo->prepare('SELECT pg_advisory_unlock(CAST(:k1 AS integer), CAST(:k2 AS integer))');
            $stmt->execute([':k1' => $advisoryKeys[0], ':k2' => $advisoryKeys[1]]);
            $result = $stmt->fetchColumn();

            return self::isDatabaseTrue($result);
        } catch (Throwable) {
            return false;
        }
    }
}
