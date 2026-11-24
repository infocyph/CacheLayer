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

        if (str_starts_with($cql, 'CREATE TABLE')) {
            return [];
        }

        if (str_contains($cql, 'cachelayer_entries_metadata')) {
            if (str_starts_with($cql, 'SELECT tag, generation')) {
                $ns = (string) ($args[0] ?? '');
                $bucket = (int) ($args[1] ?? 0);
                $tags = array_map(static fn(int|string|null $value): string => (string) $value, array_slice($args, 2));

                return array_values(array_filter(
                    $this->metadata,
                    static fn(array $row, string $key): bool => str_starts_with(
                        $key,
                        $ns . ':' . $bucket . ':',
                    ) && in_array($row['tag'], $tags, true),
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

                return [];
            }

            if (str_starts_with($cql, 'DELETE FROM')) {
                $prefix = (string) ($args[0] ?? '') . ':' . (int) ($args[1] ?? 0) . ':';
                foreach (array_keys($this->metadata) as $key) {
                    if (str_starts_with($key, $prefix)) {
                        unset($this->metadata[$key]);
                    }
                }

                return [];
            }
        }

        if (str_starts_with($cql, 'DELETE FROM') && str_contains($cql, 'AND ckey = ?')) {
            unset($this->rows[$this->rowKey($args)]);

            return [];
        }

        if (str_starts_with($cql, 'DELETE FROM') && str_contains($cql, 'ckey IN')) {
            $ns = (string) ($args[0] ?? '');
            $bucket = (int) ($args[1] ?? 0);
            foreach (array_slice($args, 2) as $key) {
                unset($this->rows[$ns . ':' . $bucket . ':' . $key]);
            }

            return [];
        }

        if (str_starts_with($cql, 'DELETE FROM')) {
            $prefix = (string) ($args[0] ?? '') . ':' . (int) ($args[1] ?? 0) . ':';
            foreach (array_keys($this->rows) as $key) {
                if (str_starts_with($key, $prefix)) {
                    unset($this->rows[$key]);
                }
            }

            return [];
        }

        if (str_starts_with($cql, 'SELECT expires')) {
            $ns = (string) ($args[0] ?? '');
            $bucket = (int) ($args[1] ?? 0);
            $matching = [];
            foreach ($this->rows as $key => $row) {
                if (str_starts_with($key, $ns . ':' . $bucket . ':')) {
                    $matching[] = $row;
                }
            }

            return array_map(
                static fn (array $row): array => ['expires' => $row['expires']],
                $matching,
            );
        }

        if (str_starts_with($cql, 'SELECT payload, expires')) {
            $row = $this->rows[$this->rowKey($args)] ?? null;

            return is_array($row) ? [$row] : [];
        }

        if (str_starts_with($cql, 'SELECT ckey, payload, expires')) {
            $this->bucketReads++;
            $ns = (string) ($args[0] ?? '');
            $bucket = (int) ($args[1] ?? 0);
            $keys = array_map(static fn(int|string|null $value): string => (string) $value, array_slice($args, 2));

            return array_values(array_filter(
                $this->rows,
                static fn(array $row): bool => in_array($row['ckey'], $keys, true),
            ));
        }

        if (str_starts_with($cql, 'BEGIN UNLOGGED BATCH')) {
            $this->writeBatches++;
            foreach (array_chunk($args, 5) as $row) {
                $this->store($row);
            }

            return [];
        }

        if (str_starts_with($cql, 'INSERT INTO')) {
            $this->store($args);

            return [];
        }

        return [];
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
        return (string) ($row[0] ?? '') . ':' . (int) ($row[1] ?? 0) . ':' . (string) ($row[2] ?? '');
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
