<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Tests\Support;

use Infocyph\CacheLayer\Counter\AtomicCounters;
use RuntimeException;

final class AtomicCounterProcessProbe
{
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
                $client = new \Redis();
                $client->connect($host, $port);
                if ($password !== '') {
                    $client->auth($password);
                }
                $counter = $backend === 'valkey'
                    ? AtomicCounters::valkey($namespace, client: $client)
                    : AtomicCounters::redis($namespace, client: $client);
                $initialized = $counter->increment($key)->initialized;
                pcntl_exec('/bin/sh', ['-c', $initialized ? 'true' : 'false']);

                throw new RuntimeException('Unable to terminate atomic counter child process.');
            }
            if ($pid > 0) {
                $children[] = $pid;
            }
        }

        $wins = 0;
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            $wins += pcntl_wexitstatus($status) === 0 ? 1 : 0;
        }

        return $wins;
    }
}
