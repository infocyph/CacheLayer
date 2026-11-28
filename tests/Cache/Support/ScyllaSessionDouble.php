<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Tests\Cache\Support;

final class ScyllaSessionDouble
{
    /** @var array<string, array{ckey:string,payload:string,expires:int|null}> */
    private array $rows = [];

    /** @var array<string, array{tag:string,generation:string}> */
    private array $metadata = [];

    public int $bucketReads = 0;

    public bool $invalidLwtResponse = false;

    public int $writeBatches = 0;

    public function prepare(string $cql): string
    {
        return $cql;
    }

    /**
     * @return array<int, array<string, mixed>>
     * @throws \InvalidArgumentException
     */
    public function execute(mixed $statement, mixed $options = []): array
    {
        if (!is_string($statement)) {
            throw new \InvalidArgumentException('Scylla test statements must be strings.');
        }
        $cql = trim($statement);
        $args = $this->extractArguments($options);

        return match (true) {
            str_starts_with($cql, 'CREATE TABLE') => [],
            str_contains($cql, 'cachelayer_entries_metadata') => $this->executeMetadata($cql, $args),
            str_contains($cql, ' IF ') => $this->executeConditional($cql, $args),
            str_starts_with($cql, 'DELETE FROM') => $this->deleteRows($cql, $args),
            str_starts_with($cql, 'SELECT') => $this->selectRows($cql, $args),
            str_starts_with($cql, 'BEGIN UNLOGGED BATCH'), str_starts_with($cql, 'INSERT INTO') => $this->writeRows($cql, $args),
            default => [],
        };
    }

    /**
     * @param list<int|string|null> $args
     * @return list<array<string, bool>>
     */
    private function executeConditional(string $cql, array $args): array
    {
        if ($this->invalidLwtResponse) {
            return [];
        }
        $applied = false;
        if (str_starts_with($cql, 'INSERT')) {
            $key = $this->rowKey($args);
            if (!isset($this->rows[$key])) {
                $this->store($args);
                $applied = true;
            }
        } elseif (str_starts_with($cql, 'UPDATE')) {
            $identity = array_slice($args, 3, 3);
            $key = $this->rowKey($identity);
            if (isset($this->rows[$key]) && $this->rows[$key]['payload'] === ($args[6] ?? null)) {
                $this->store([...$identity, $args[1] ?? null, $args[2] ?? null]);
                $applied = true;
            }
        } elseif (str_starts_with($cql, 'DELETE')) {
            $key = $this->rowKey($args);
            if (isset($this->rows[$key]) && (str_contains($cql, 'IF EXISTS') || $this->rows[$key]['payload'] === ($args[3] ?? null))) {
                unset($this->rows[$key]);
                $applied = true;
            }
        }

        return [['[applied]' => $applied]];
    }

    /**
     * @param list<int|string|null> $args
     * @return list<array{tag:string,generation:string}>
     */
    private function executeMetadata(string $cql, array $args): array
    {
        $prefix = $this->partitionPrefix($args);
        if (str_starts_with($cql, 'SELECT tag, generation')) {
            $tags = array_map(static fn(int|string|null $value): string => (string) $value, array_slice($args, 2));

            return array_values(array_filter(
                $this->metadata,
                static fn(array $row, string $key): bool => str_starts_with($key, $prefix)
                    && in_array($row['tag'], $tags, true),
                ARRAY_FILTER_USE_BOTH,
            ));
        }

        if (str_starts_with($cql, 'INSERT INTO')) {
            $key = $this->rowKey($args);
            if (!str_contains($cql, 'IF NOT EXISTS') || !isset($this->metadata[$key])) {
                $this->metadata[$key] = [
                    'tag' => (string) ($args[2] ?? ''),
                    'generation' => (string) ($args[3] ?? ''),
                ];
            }
        } elseif (str_starts_with($cql, 'DELETE FROM')) {
            foreach (array_keys($this->metadata) as $key) {
                if (str_starts_with($key, $prefix)) {
                    unset($this->metadata[$key]);
                }
            }
        }

        return [];
    }

    /**
     * @param list<int|string|null> $args
     * @return list<never>
     */
    private function deleteRows(string $cql, array $args): array
    {
        if (str_contains($cql, 'AND ckey = ?')) {
            unset($this->rows[$this->rowKey($args)]);

            return [];
        }

        $prefix = $this->partitionPrefix($args);
        if (str_contains($cql, 'ckey IN')) {
            foreach (array_slice($args, 2) as $key) {
                unset($this->rows[$prefix . $key]);
            }

            return [];
        }

        foreach (array_keys($this->rows) as $key) {
            if (str_starts_with($key, $prefix)) {
                unset($this->rows[$key]);
            }
        }

        return [];
    }

    /**
     * @param list<int|string|null> $args
     * @return list<array<string, int|string|null>>
     */
    private function selectRows(string $cql, array $args): array
    {
        if (str_starts_with($cql, 'SELECT payload, expires')) {
            $row = $this->rows[$this->rowKey($args)] ?? null;

            return $row === null ? [] : [$row];
        }

        $prefix = $this->partitionPrefix($args);
        $rows = array_values(array_filter(
            $this->rows,
            static fn(string $key): bool => str_starts_with($key, $prefix),
            ARRAY_FILTER_USE_KEY,
        ));
        if (str_starts_with($cql, 'SELECT ckey FROM')) {
            return array_map(static fn(array $row): array => ['ckey' => $row['ckey']], $rows);
        }
        if (str_starts_with($cql, 'SELECT expires')) {
            return array_map(static fn(array $row): array => ['expires' => $row['expires']], $rows);
        }

        if (str_starts_with($cql, 'SELECT ckey, payload, expires')) {
            $this->bucketReads++;
            $keys = array_map(static fn(int|string|null $value): string => (string) $value, array_slice($args, 2));

            return array_values(array_filter(
                $rows,
                static fn(array $row): bool => in_array($row['ckey'], $keys, true),
            ));
        }

        return [];
    }

    /**
     * @param list<int|string|null> $args
     * @return list<never>
     */
    private function writeRows(string $cql, array $args): array
    {
        if (str_starts_with($cql, 'BEGIN UNLOGGED BATCH')) {
            $this->writeBatches++;
            foreach (array_chunk($args, 5) as $row) {
                $this->store($row);
            }
        } else {
            $this->store($args);
        }

        return [];
    }

    /** @param list<int|string|null> $args */
    private function partitionPrefix(array $args): string
    {
        return (string) ($args[0] ?? '') . ':' . (int) ($args[1] ?? 0) . ':';
    }

    /**
     * @return list<int|string|null>
     * @throws \InvalidArgumentException
     */
    private function extractArguments(mixed $options): array
    {
        if (is_array($options) && is_array($options['arguments'] ?? null)) {
            $arguments = [];
            foreach ($options['arguments'] as $argument) {
                if (!is_int($argument) && !is_string($argument) && $argument !== null) {
                    throw new \InvalidArgumentException('Scylla test arguments must be scalar strings, integers or null.');
                }
                $arguments[] = $argument;
            }

            return $arguments;
        }

        return [];
    }

    /** @param list<int|string|null> $row */
    private function rowKey(array $row): string
    {
        return $this->partitionPrefix($row) . (string) ($row[2] ?? '');
    }

    /** @param list<int|string|null> $row */
    private function store(array $row): void
    {
        $key = $this->rowKey($row);
        $this->rows[$key] = [
            'ckey' => (string) ($row[2] ?? ''),
            'payload' => (string) ($row[3] ?? ''),
            'expires' => is_numeric($row[4] ?? null) ? (int) $row[4] : null,
        ];
    }
}
