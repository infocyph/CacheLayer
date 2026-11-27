<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Tests\Support;

use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\CacheLayer\Cache\Item\CacheItem;
use PDO;
use UnexpectedValueException;

final class CacheFixture
{
    /** @throws UnexpectedValueException */
    public static function countRows(PDO $connection, string $sql): int
    {
        $statement = $connection->query($sql);
        if ($statement === false) {
            throw new UnexpectedValueException('Unable to query fixture row count.');
        }
        $count = $statement->fetchColumn();
        if (is_int($count) && $count >= 0) {
            return $count;
        }
        if (is_string($count) && ctype_digit($count) && filter_var($count, FILTER_VALIDATE_INT) !== false) {
            return (int) $count;
        }

        throw new UnexpectedValueException('Invalid fixture row count.');
    }

    /**
     * @param array<mixed> $backend
     * @return array{string, string, string}
     * @throws UnexpectedValueException
     */
    public static function pdoCredentials(array $backend): array
    {
        if (count($backend) !== 3 || !isset($backend[0], $backend[1], $backend[2])
            || !is_string($backend[0]) || !is_string($backend[1]) || !is_string($backend[2])) {
            throw new UnexpectedValueException('Invalid PDO fixture credentials.');
        }

        return [$backend[0], $backend[1], $backend[2]];
    }

    /** @throws UnexpectedValueException */
    public static function waitForChild(int $pid): int
    {
        if (pcntl_waitpid($pid, $status) !== $pid) {
            throw new UnexpectedValueException('Unable to wait for the expected child process.');
        }

        return self::exitStatus($status);
    }

    /** @throws UnexpectedValueException */
    private static function exitStatus(mixed $status): int
    {
        if (!is_int($status) || !pcntl_wifexited($status)) {
            throw new UnexpectedValueException('Child process did not exit normally.');
        }

        return self::requireExitCode(pcntl_wexitstatus($status));
    }

    /** @throws UnexpectedValueException */
    private static function requireExitCode(mixed $code): int
    {
        if (!is_int($code)) {
            throw new UnexpectedValueException('Unable to read child exit status.');
        }

        return $code;
    }

    public static function environment(string $name): ?string
    {
        $value = getenv($name);

        return $value === false || $value === '' || $value === '0' ? null : $value;
    }

    /**
     * Launch independent PHP processes before supplying their arguments.
     * @param array<string, int|string> $configuration
     * @return list<int>
     * @throws \RuntimeException
     * @throws \JsonException
     */
    public static function cacheRace(array $configuration, int $workers = 12): array
    {
        $children = [];
        try {
            for ($worker = 1; $worker <= $workers; ++$worker) {
                $process = proc_open(
                    [PHP_BINARY, __DIR__ . '/CacheRaceProcess.php'],
                    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                    $pipes,
                );
                if (!is_resource($process)) {
                    throw new \RuntimeException('Unable to launch independent cache race worker.');
                }
                $children[] = ['process' => $process, 'pipes' => $pipes];
            }

            foreach ($children as $index => $child) {
                $payload = json_encode($configuration + ['worker' => $index + 1], JSON_THROW_ON_ERROR);
                if (fwrite($child['pipes'][0], $payload) !== strlen($payload)) {
                    throw new \RuntimeException('Unable to send cache race configuration.');
                }
                fclose($child['pipes'][0]);
            }

            $deadline = hrtime(true) + 20_000_000_000;
            $results = [];
            foreach ($children as $child) {
                $process = $child['process'];
                do {
                    $status = proc_get_status($process);
                    if (!$status['running']) {
                        break;
                    }
                    if (hrtime(true) >= $deadline) {
                        throw new \RuntimeException('Independent cache race timed out.');
                    }
                    usleep(10_000);
                } while (true);

                $stdout = stream_get_contents($child['pipes'][1]);
                $stderr = stream_get_contents($child['pipes'][2]);
                fclose($child['pipes'][1]);
                fclose($child['pipes'][2]);
                $exit = proc_close($process);
                if ($exit !== 0 || !is_string($stdout)
                    || preg_match('/^[0-9]+$/D', trim($stdout)) !== 1) {
                    throw new \RuntimeException('Independent cache race worker failed: ' . (is_string($stderr) ? $stderr : 'no diagnostic'));
                }
                $results[] = (int) trim($stdout);
            }

            return $results;
        } finally {
            foreach ($children as $child) {
                foreach ($child['pipes'] as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }
                if (is_resource($child['process'])) {
                    proc_terminate($child['process']);
                    proc_close($child['process']);
                }
            }
        }
    }

    /**
     * @throws UnexpectedValueException
     * @throws \Infocyph\CacheLayer\Exceptions\CacheBackendException
     * @throws \Infocyph\CacheLayer\Exceptions\CacheInvalidArgumentException
     */
    public static function item(Cache $cache, string $key): CacheItem
    {
        $item = $cache->getItem($key);
        if (!$item instanceof CacheItem) {
            throw new UnexpectedValueException('The test fixture requires a native CacheLayer item.');
        }

        return $item;
    }
}
