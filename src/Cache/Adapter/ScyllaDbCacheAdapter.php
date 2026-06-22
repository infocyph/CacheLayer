<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cache\Adapter;

use Cassandra\ExecutionOptions;
use Cassandra\SimpleStatement;
use Infocyph\CacheLayer\Cache\Item\GenericCacheItem;
use Psr\Cache\CacheItemInterface;
use RuntimeException;
use Throwable;
use Traversable;

final class ScyllaDbCacheAdapter extends AbstractCacheAdapter
{
    private readonly string $ns;

    private readonly string $qualifiedTable;

    /** @var array<string, mixed> */
    private array $preparedStatements = [];

    public function __construct(
        private readonly object $session,
        string $keyspace = 'cachelayer',
        string $table = 'cachelayer_entries',
        string $namespace = 'default',
    ) {
        if (!$this->supportsSessionMethod('execute')) {
            throw new RuntimeException('ScyllaDbCacheAdapter requires session method `execute()`.');
        }

        $this->ns = sanitize_cache_ns($namespace);
        $resolvedTable = self::validateIdentifier($table, 'table');
        $resolvedKeyspace = self::validateIdentifier($keyspace, 'keyspace');
        $this->qualifiedTable = $resolvedKeyspace . '.' . $resolvedTable;

        $this->createSchemaIfMissing();
    }

    public function clear(): bool
    {
        $this->executeCql(
            "DELETE FROM {$this->qualifiedTable} WHERE ns = ?",
            [$this->ns],
        );
        $this->deferred = [];

        return true;
    }

    public function count(): int
    {
        $rows = $this->queryRows(
            "SELECT expires FROM {$this->qualifiedTable} WHERE ns = ?",
            [$this->ns],
        );
        $now = time();
        $count = 0;

        foreach ($rows as $row) {
            $expiresAt = $this->normalizeExpiry($row['expires'] ?? null);
            if ($expiresAt === null || $expiresAt > $now) {
                $count++;
            }
        }

        return $count;
    }

    public function deleteItem(string $key): bool
    {
        $this->executeCql(
            "DELETE FROM {$this->qualifiedTable} WHERE ns = ? AND ckey = ?",
            [$this->ns, $key],
        );

        return true;
    }

    /**
     * @param array $keys The keys argument.
     * @phpstan-param list<string> $keys
     */
    public function deleteItems(array $keys): bool
    {
        foreach ($keys as $key) {
            $this->deleteItem((string) $key);
        }

        return true;
    }

    public function getItem(string $key): GenericCacheItem
    {
        $row = $this->firstRow(
            "SELECT payload, expires FROM {$this->qualifiedTable} WHERE ns = ? AND ckey = ? LIMIT 1",
            [$this->ns, $key],
        );

        if ($row === null) {
            return $this->genericMiss($key);
        }

        $expiresAt = $this->normalizeExpiry($row['expires'] ?? null);
        if ($expiresAt !== null && $expiresAt <= time()) {
            return $this->genericDeleteAndMiss($key);
        }

        $payload = $this->normalizeString($row['payload'] ?? null);

        return $this->genericFromBase64($key, $payload);
    }

    public function hasItem(string $key): bool
    {
        return $this->getItem($key)->isHit();
    }

    /**
     * @param array $keys The keys argument.
     * @phpstan-param list<string> $keys
     * @phpstan-return array<string, GenericCacheItem>
     */
    public function multiFetch(array $keys): array
    {
        return $this->multiFetchItems($keys, $this->getItem(...));
    }

    public function save(CacheItemInterface $item): bool
    {
        return $this->saveEncoded($item, function (CacheItemInterface $saveItem, array $expires): bool {
            $this->executeCql(
                "INSERT INTO {$this->qualifiedTable} (ns, ckey, payload, expires) VALUES (?, ?, ?, ?)",
                [
                    $this->ns,
                    $saveItem->getKey(),
                    base64_encode(CachePayloadCodec::encode($saveItem->get(), $expires['expiresAt'])),
                    $expires['expiresAt'],
                ],
            );

            return true;
        });
    }

    protected function supportsItem(CacheItemInterface $item): bool
    {
        return $item instanceof GenericCacheItem;
    }

    private static function validateIdentifier(string $value, string $label): string
    {
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $value)) {
            throw new RuntimeException(sprintf('Invalid ScyllaDB %s name `%s`.', $label, $value));
        }

        return $value;
    }

    /**
     * @param string $method The method argument.
     * @param array $arguments The arguments argument.
     * @phpstan-param array<int, mixed> $arguments
     */
    private function callSession(string $method, array $arguments): mixed
    {
        $callable = [$this->session, $method];
        if (!is_callable($callable)) {
            throw new RuntimeException(
                sprintf('ScyllaDbCacheAdapter requires session method `%s()`.', $method),
            );
        }

        return $callable(...$arguments);
    }

    private function createSchemaIfMissing(): void
    {
        $this->executeCql(
            "CREATE TABLE IF NOT EXISTS {$this->qualifiedTable} (
                ns text,
                ckey text,
                payload text,
                expires bigint,
                PRIMARY KEY (ns, ckey)
            )",
        );
    }

    /**
     * @param string $cql The cql argument.
     * @param array $arguments The arguments argument.
     * @phpstan-param array<int, mixed> $arguments
     */
    private function executeCql(string $cql, array $arguments = []): mixed
    {
        $statement = $this->statementFor($cql);
        $options = $this->executionOptions($arguments);

        try {
            return $this->callSession('execute', [$statement, $options]);
        } catch (Throwable) {
            return $this->callSession('execute', [$statement]);
        }
    }

    /**
     * @param array $arguments The arguments argument.
     * @phpstan-param array<int, mixed> $arguments
     */
    private function executionOptions(array $arguments): mixed
    {
        $options = ['arguments' => $arguments];
        if (class_exists(ExecutionOptions::class)) {
            return new ExecutionOptions($options);
        }

        return $options;
    }

    /**
     * @param string $cql The cql argument.
     * @param array $arguments The arguments argument.
     * @phpstan-param array<int, mixed> $arguments
     * @phpstan-return array<string, mixed>|null
     */
    private function firstRow(string $cql, array $arguments = []): ?array
    {
        foreach ($this->queryRows($cql, $arguments) as $row) {
            return $row;
        }

        return null;
    }

    private function normalizeExpiry(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value) || (is_string($value) && is_numeric($value))) {
            return (int) $value;
        }

        if (is_object($value) && is_callable([$value, '__toString'])) {
            $stringValue = (string) $value;
            if (is_numeric($stringValue)) {
                return (int) $stringValue;
            }
        }

        return null;
    }

    /**
     * @param array $rows The rows argument.
     * @phpstan-param array<mixed, mixed> $rows
     * @phpstan-return array<int, array<string, mixed>>
     */
    private function normalizeRows(array $rows): array
    {
        $normalized = [];
        foreach ($rows as $row) {
            $assoc = AdapterValueNormalizer::fromJsonOrArrayLike($row);
            if ($assoc !== null) {
                $normalized[] = $assoc;
            }
        }

        return $normalized;
    }

    private function normalizeString(mixed $value): ?string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_object($value) && is_callable([$value, '__toString'])) {
            return (string) $value;
        }

        return null;
    }

    /**
     * @param string $cql The cql argument.
     * @param array $arguments The arguments argument.
     * @phpstan-param array<int, mixed> $arguments
     * @phpstan-return array<int, array<string, mixed>>
     */
    private function queryRows(string $cql, array $arguments = []): array
    {
        $result = $this->executeCql($cql, $arguments);

        if (is_array($result)) {
            return $this->normalizeRows($result);
        }

        if ($result instanceof Traversable) {
            return $this->normalizeRows(iterator_to_array($result));
        }

        if (is_object($result) && is_callable([$result, 'toArray'])) {
            $rows = $result->toArray();

            return is_array($rows) ? $this->normalizeRows($rows) : [];
        }

        return [];
    }

    private function statementFor(string $cql): mixed
    {
        if ($this->supportsSessionMethod('prepare')) {
            if (!array_key_exists($cql, $this->preparedStatements)) {
                $this->preparedStatements[$cql] = $this->callSession('prepare', [$cql]);
            }

            return $this->preparedStatements[$cql];
        }

        if (class_exists(SimpleStatement::class)) {
            return new SimpleStatement($cql);
        }

        return $cql;
    }

    private function supportsSessionMethod(string $method): bool
    {
        return method_exists($this->session, $method) || is_callable([$this->session, $method]);
    }
}
