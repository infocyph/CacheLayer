<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Tests\Support;

use Infocyph\CacheLayer\Counter\AtomicCounters;
use RuntimeException;

final class AtomicCounterProcessProbe
{
    /**
     * @throws RuntimeException
     * @throws \UnexpectedValueException
     */
    public static function initializedWinners(
        string $backend,
        string $host,
        int $port,
        string $password,
        string $namespace,
        string $key,
        int $workers = 8,
    ): int {
        if (!function_exists('pcntl_fork')) {
            throw new RuntimeException('pcntl is required for atomic counter contention tests.');
        }

        $children = [];
        for ($worker = 0; $worker < $workers; ++$worker) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                self::runChild($backend, $host, $port, $password, $namespace, $key);
            }
            if ($pid < 0) {
                foreach ($children as $child) {
                    pcntl_waitpid($child, $status);
                }
                throw new RuntimeException('Unable to fork all atomic counter contenders.');
            }
            $children[] = $pid;
        }

        $wins = 0;
        foreach ($children as $pid) {
            $wins += CacheFixture::waitForChild($pid) === 0 ? 1 : 0;
        }

        return $wins;
    }
    /** @throws RuntimeException */
    private static function runChild(
        string $backend,
        string $host,
        int $port,
        #[\SensitiveParameter] string $password,
        string $namespace,
        string $key,
    ): never {
        $client = new \Redis();
        if (!$client->connect($host, $port) || ($password !== '' && !$client->auth($password))) {
            throw new RuntimeException('Unable to connect atomic counter contender.');
        }
        $counter = $backend === 'valkey'
            ? AtomicCounters::valkey($namespace, client: $client)
            : AtomicCounters::redis($namespace, client: $client);
        $initialized = $counter->increment($key)->initialized;
        pcntl_exec('/bin/sh', ['-c', $initialized ? 'true' : 'false']);

        throw new RuntimeException('Unable to terminate atomic counter child process.');
    }
}
