<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cache\Lock;

final readonly class FileLockProvider implements LockProviderInterface
{
    private string $directory;
    private int $retrySleepMicros;

    public function __construct(
        ?string $directory = null,
        int $retrySleepMicros = 50_000,
    ) {
        $this->directory = rtrim(
            $directory ?? (sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cachelayer_locks'),
            DIRECTORY_SEPARATOR,
        );
        $this->retrySleepMicros = max(1_000, $retrySleepMicros);
    }

    public function acquire(string $key, float $waitSeconds): ?LockHandle
    {
        $activeLocks = &self::activeRegistry();
        if (isset($activeLocks[$key])) {
            return null;
        }

        if (!is_dir($this->directory)) {
            @mkdir($this->directory, 0770, true);
        }

        $path = $this->directory . DIRECTORY_SEPARATOR . hash('xxh128', $key) . '.lock';
        $handle = @fopen($path, 'c+');
        if (!is_resource($handle)) {
            return null;
        }

        $deadline = microtime(true) + max(0.0, $waitSeconds);
        while (!@flock($handle, LOCK_EX | LOCK_NB)) {
            if (microtime(true) >= $deadline) {
                @fclose($handle);
                return null;
            }

            usleep($this->retrySleepMicros);
        }

        $token = hash('xxh3', $key . '|' . uniqid('', true));
        $activeLocks[$key] = true;

        return new LockHandle($key, $token, $handle);
    }

    public function release(?LockHandle $handle): void
    {
        if (!$handle instanceof LockHandle) {
            return;
        }

        $activeLocks = &self::activeRegistry();

        if (is_resource($handle->resource)) {
            @flock($handle->resource, LOCK_UN);
            @fclose($handle->resource);
        }

        unset($activeLocks[$handle->key]);
    }

    /**
     * @return array<string, bool>
     */
    private static function &activeRegistry(): array
    {
        static $registry = [];
        return $registry;
    }
}
