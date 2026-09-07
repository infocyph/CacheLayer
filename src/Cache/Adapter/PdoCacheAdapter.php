<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cache\Adapter;

use Infocyph\CacheLayer\Cache\CacheInput;
use Infocyph\CacheLayer\Cache\CacheRecord;
use Infocyph\CacheLayer\Cache\Item\CacheItem;
use PDO;
use PDOException;
use Psr\Cache\CacheItemInterface;
use RuntimeException;
use Throwable;

final class PdoCacheAdapter extends AbstractCacheAdapter implements ConditionalAtomicCachePoolInterface
{
    private const int BATCH_SIZE = 250;
    private const string DEFAULT_SQLITE_DIR = 'cachelayer/pdo';
    private const string KIND_DATA = 'data';
    private const string KIND_TAG = 'tag';

    private readonly string $driver;
    private readonly string $namespace;
    private readonly PDO $pdo;
    private readonly string $table;

    public function __construct(
        string $namespace = 'default',
        ?string $dsn = null,
        ?string $username = null,
        ?string $password = null,
        ?PDO $pdo = null,
        string $table = 'cachelayer_entries',
        bool $initializeSchema = true,
    ) {
        if (preg_match('/^[A-Za-z0-9_]+$/D', $table) !== 1) {
            throw new RuntimeException('Invalid PDO cache table name.');
        }

        $this->namespace = CacheInput::namespace($namespace);
        $this->table = $table;
        $resolvedDsn = $dsn ?? 'sqlite:' . self::defaultSqliteFileForNamespace($this->namespace);
        $this->pdo = $pdo ?? new PDO($resolvedDsn, $username, $password);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $this->driver = is_string($driver) ? $driver : '';
        if ($this->driver === 'sqlite') {
            $this->pdo->exec('PRAGMA journal_mode=WAL; PRAGMA synchronous=NORMAL; PRAGMA busy_timeout=5000;');
        }
        if ($initializeSchema) {
            PdoCacheSchema::install($this->pdo, $this->table);
        }
    }

    public function atomicCompareAndSet(string $key, mixed $expected, CacheItemInterface $replacement): bool
    {
        if (!$this->supportsAtomicCache() || !$this->supportsItem($replacement)) {
            return false;
        }
        $expiration = CachePayloadCodec::expirationFromItem($replacement);
        if ($expiration['ttl'] !== null && $expiration['ttl'] <= 0) {
            return false;
        }

        return $this->atomicTransaction(function () use ($key, $expected, $replacement, $expiration): bool {
            $row = $this->atomicFetchRow($key);
            $record = is_array($row) ? $this->recordFromRow($row) : null;
            if (!$record instanceof CacheRecord
                || !$this->recordTagsAreCurrent($record)
                || $record->value !== $expected) {
                return false;
            }

            return $this->atomicUpdateExisting(
                $key,
                $this->encodeItem($replacement, $expiration['expiresAt']),
                $expiration['expiresAt'],
            );
        });
    }

    public function atomicGetAndDelete(string $key): CacheItemInterface
    {
        if (!$this->supportsAtomicCache()) {
            return $this->genericMiss($key);
        }

        return $this->atomicTransaction(function () use ($key): CacheItemInterface {
            $row = $this->atomicFetchRow($key);
            if (!is_array($row)) {
                return $this->genericMiss($key);
            }

            $record = $this->recordFromRow($row);
            $this->deleteDataRow($key);
            if (!$record instanceof CacheRecord || !$this->recordTagsAreCurrent($record)) {
                return $this->genericMiss($key);
            }

            return $this->genericItemFromRecord($key, $record);
        });
    }

    public function atomicSetIfAbsent(CacheItemInterface $item): bool
    {
        if (!$this->supportsAtomicCache() || !$this->supportsItem($item)) {
            return false;
        }
        $expiration = CachePayloadCodec::expirationFromItem($item);
        if ($expiration['ttl'] !== null && $expiration['ttl'] <= 0) {
            return false;
        }

        return $this->atomicTransaction(function () use ($item, $expiration): bool {
            $key = $item->getKey();
            $row = $this->atomicFetchRow($key);
            $record = is_array($row) ? $this->recordFromRow($row) : null;
            if ($record instanceof CacheRecord && $this->recordTagsAreCurrent($record)) {
                return false;
            }

            $payload = $this->encodeItem($item, $expiration['expiresAt']);
            if (is_array($row)) {
                return $this->atomicUpdateExisting($key, $payload, $expiration['expiresAt']);
            }

            return $this->atomicInsertIfMissing($key, $payload, $expiration['expiresAt']);
        });
    }

    public function supportsAtomicCache(): bool
    {
        return in_array($this->driver, ['sqlite', 'pgsql', 'mysql', 'mariadb'], true);
    }

    public static function defaultSqliteFileForNamespace(string $namespace): string
    {
        $directory = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, self::DEFAULT_SQLITE_DIR);
        if (is_link($directory)) {
            throw new RuntimeException("Refusing symlinked SQLite cache directory: {$directory}");
        }
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException("Unable to create SQLite cache directory: {$directory}");
        }
        $permissions = fileperms($directory);
        if (!is_writable($directory) || ($permissions !== false && (($permissions & 0x0002) === 0x0002))) {
            throw new RuntimeException("SQLite cache directory must be writable and not world-writable: {$directory}");
        }

        return $directory . DIRECTORY_SEPARATOR . 'cache_' . CacheInput::namespace($namespace) . '.sqlite';
    }

    public function clear(): bool
    {
        $statement = $this->pdo->prepare("DELETE FROM {$this->table} WHERE namespace = ?");
        $cleared = $statement->execute([$this->namespace]);
        $this->deferred = [];

        return $cleared;
    }

    public function deleteItem(string $key): bool
    {
        return $this->deleteDataRow($key);
    }

    public function deleteItems(array $keys): bool
    {
        return $this->deleteByKind(self::KIND_DATA, $keys);
    }

    public function getClient(): PDO
    {
        return $this->pdo;
    }

    public function getItem(string $key): CacheItem
    {
        $rows = $this->fetchRows(self::KIND_DATA, [$key]);
        $row = $rows[$key] ?? null;
        if (!is_array($row)) {
            return $this->genericMiss($key);
        }

        $item = $this->hydrate($key, $row);
        if ($item instanceof CacheItem) {
            return $item;
        }
        $this->deleteItem($key);

        return $this->genericMiss($key);
    }

    #[\Override]
    public function getTagGenerations(array $tags): array
    {
        if ($tags === []) {
            return [];
        }

        $rows = $this->fetchRows(self::KIND_TAG, $tags);
        $generations = [];
        $initialize = [];
        foreach ($tags as $tag) {
            $row = $rows[$tag] ?? null;
            $generation = is_array($row) ? $row['payload'] : null;
            if (!self::isGeneration($generation)) {
                $generation = self::newGeneration();
                $initialize[] = [self::KIND_TAG, $tag, $generation, null];
            }
            $generations[$tag] = strtolower((string) $generation);
        }
        if (!$this->upsertRows($initialize)) {
            throw new RuntimeException('Unable to initialize PDO tag generations.');
        }

        return $generations;
    }

    public function hasItem(string $key): bool
    {
        return $this->getItem($key)->isHit();
    }

    public function multiFetch(array $keys): array
    {
        $rows = $this->fetchRows(self::KIND_DATA, $keys);
        $items = [];
        $stale = [];
        foreach ($keys as $key) {
            $row = $rows[$key] ?? null;
            $item = is_array($row) ? $this->hydrate($key, $row) : null;
            $items[$key] = $item ?? $this->genericMiss($key);
            if (is_array($row) && $item === null) {
                $stale[] = $key;
            }
        }
        $this->deleteByKind(self::KIND_DATA, $stale);

        return $items;
    }

    public function pruneExpired(int $limit = 1000): int
    {
        if ($limit < 1) {
            throw new RuntimeException('PDO prune limit must be positive.');
        }
        $statement = $this->pdo->prepare(
            "SELECT cache_key FROM {$this->table} WHERE namespace = ? AND kind = ? "
            . 'AND expires IS NOT NULL AND expires <= ? LIMIT ?',
        );
        $statement->bindValue(1, $this->namespace, PDO::PARAM_STR);
        $statement->bindValue(2, self::KIND_DATA, PDO::PARAM_STR);
        $statement->bindValue(3, time(), PDO::PARAM_INT);
        $statement->bindValue(4, $limit, PDO::PARAM_INT);
        $statement->execute();
        $keys = $statement->fetchAll(PDO::FETCH_COLUMN);
        $keys = array_values(array_filter($keys, is_string(...)));

        return $this->deleteByKind(self::KIND_DATA, $keys) ? count($keys) : 0;
    }

    #[\Override]
    public function rotateTagGenerations(array $tags): bool
    {
        $rows = [];
        foreach ($tags as $tag) {
            $rows[] = [self::KIND_TAG, $tag, self::newGeneration(), null];
        }

        return $this->upsertRows($rows);
    }

    public function save(CacheItemInterface $item): bool
    {
        if (!$this->supportsItem($item)) {
            return false;
        }
        $expiration = CachePayloadCodec::expirationFromItem($item);
        if ($expiration['ttl'] !== null && $expiration['ttl'] <= 0) {
            return $this->deleteItem($item->getKey());
        }

        return $this->upsertRows([[
            self::KIND_DATA,
            $item->getKey(),
            $this->encodeItem($item, $expiration['expiresAt']),
            $expiration['expiresAt'],
        ]]);
    }

    public function saveItems(array $items): bool
    {
        $rows = [];
        $expired = [];
        foreach ($items as $item) {
            if (!$this->supportsItem($item)) {
                return false;
            }
            $expiration = CachePayloadCodec::expirationFromItem($item);
            if ($expiration['ttl'] !== null && $expiration['ttl'] <= 0) {
                $expired[] = $item->getKey();
                continue;
            }
            $rows[] = [self::KIND_DATA, $item->getKey(), $this->encodeItem($item, $expiration['expiresAt']), $expiration['expiresAt']];
        }

        return $this->deleteByKind(self::KIND_DATA, $expired) && $this->upsertRows($rows);
    }

    private function atomicFetchRow(string $key): ?array
    {
        $suffix = in_array($this->driver, ['pgsql', 'mysql', 'mariadb'], true) ? ' FOR UPDATE' : '';
        $statement = $this->pdo->prepare(
            "SELECT payload, expires FROM {$this->table} WHERE namespace = ? AND kind = ? AND cache_key = ?{$suffix}",
        );
        $statement->execute([$this->namespace, self::KIND_DATA, $key]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        $payload = $row['payload'] ?? null;
        if (is_resource($payload)) {
            $payload = stream_get_contents($payload);
        }
        if (!is_string($payload)) {
            return null;
        }

        return ['payload' => $payload, 'expires' => is_numeric($row['expires'] ?? null) ? (int) $row['expires'] : null];
    }

    private function atomicInsertIfMissing(string $key, string $payload, ?int $expires): bool
    {
        if (in_array($this->driver, ['pgsql', 'sqlite'], true)) {
            $sql = "INSERT INTO {$this->table} (namespace, kind, cache_key, payload, expires) VALUES (?, ?, ?, ?, ?) "
                . 'ON CONFLICT (namespace, kind, cache_key) DO NOTHING';
        } else {
            $sql = "INSERT IGNORE INTO {$this->table} (namespace, kind, cache_key, payload, expires) VALUES (?, ?, ?, ?, ?)";
        }
        $statement = $this->pdo->prepare($sql);
        $statement->execute([$this->namespace, self::KIND_DATA, $key, $payload, $expires]);

        return $statement->rowCount() === 1;
    }

    private function atomicTransaction(callable $callback): mixed
    {
        if ($this->pdo->inTransaction()) {
            throw new RuntimeException('Atomic PDO cache operations require ownership of the PDO transaction.');
        }
        $sqlite = $this->driver === 'sqlite';
        if ($sqlite) {
            $this->pdo->exec('BEGIN IMMEDIATE');
        } else {
            $this->pdo->beginTransaction();
        }

        try {
            $result = $callback();
            $sqlite ? $this->pdo->exec('COMMIT') : $this->pdo->commit();
            return $result;
        } catch (Throwable $failure) {
            if ($this->pdo->inTransaction()) {
                $sqlite ? $this->pdo->exec('ROLLBACK') : $this->pdo->rollBack();
            }
            throw $failure;
        }
    }

    private function atomicUpdateExisting(string $key, string $payload, ?int $expires): bool
    {
        $statement = $this->pdo->prepare(
            "UPDATE {$this->table} SET payload = ?, expires = ? WHERE namespace = ? AND kind = ? AND cache_key = ?",
        );
        return $statement->execute([$payload, $expires, $this->namespace, self::KIND_DATA, $key]);
    }

    private function deleteDataRow(string $key): bool
    {
        $statement = $this->pdo->prepare(
            "DELETE FROM {$this->table} WHERE namespace = ? AND kind = ? AND cache_key = ?",
        );
        return $statement->execute([$this->namespace, self::KIND_DATA, $key]);
    }

    private function deleteByKind(string $kind, array $keys): bool
    {
        foreach (array_chunk($keys, self::BATCH_SIZE) as $chunk) {
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            $parameters = [$this->namespace, $kind, ...$chunk];
            if (!$this->pdo->prepare(
                "DELETE FROM {$this->table} WHERE namespace = ? AND kind = ? AND cache_key IN ({$marks})",
            )->execute($parameters)) {
                return false;
            }
        }

        return true;
    }

    private function fetchRows(string $kind, array $keys): array
    {
        $rows = [];
        foreach (array_chunk($keys, self::BATCH_SIZE) as $chunk) {
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            $statement = $this->pdo->prepare(
                "SELECT cache_key, payload, expires FROM {$this->table} WHERE namespace = ? AND kind = ? AND cache_key IN ({$marks})",
            );
            $statement->execute([$this->namespace, $kind, ...$chunk]);
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (!is_array($row) || !is_string($row['cache_key'] ?? null)) {
                    continue;
                }
                $payload = $row['payload'] ?? null;
                if (is_resource($payload)) {
                    $payload = stream_get_contents($payload);
                }
                if (!is_string($payload)) {
                    continue;
                }
                $rows[$row['cache_key']] = [
                    'payload' => $payload,
                    'expires' => is_numeric($row['expires'] ?? null) ? (int) $row['expires'] : null,
                ];
            }
        }

        return $rows;
    }

    private function hydrate(string $key, array $row): ?CacheItem
    {
        if (CachePayloadCodec::isExpired($row['expires'])) {
            return null;
        }
        $record = $this->decodeRecordFromBlob($row['payload']);

        return $record === null ? null : $this->genericItemFromRecord($key, $record);
    }

    private function recordFromRow(array $row): ?CacheRecord
    {
        if (CachePayloadCodec::isExpired($row['expires'])) {
            return null;
        }
        $record = $this->decodeRecordFromBlob($row['payload']);

        return $record instanceof CacheRecord ? $record : null;
    }

    private function recordTagsAreCurrent(CacheRecord $record): bool
    {
        if ($record->tags === []) {
            return true;
        }
        $rows = $this->fetchRows(self::KIND_TAG, array_keys($record->tags));
        foreach ($record->tags as $tag => $generation) {
            if (($rows[$tag]['payload'] ?? null) !== $generation) {
                return false;
            }
        }

        return true;
    }

    private function upsertChunk(array $rows): bool
    {
        if (!in_array($this->driver, ['pgsql', 'sqlite', 'mysql', 'mariadb'], true)) {
            return $this->upsertGenericRows($rows);
        }

        $values = implode(',', array_fill(0, count($rows), '(?, ?, ?, ?, ?)'));
        $suffix = in_array($this->driver, ['pgsql', 'sqlite'], true)
            ? 'ON CONFLICT (namespace, kind, cache_key) DO UPDATE SET payload = EXCLUDED.payload, expires = EXCLUDED.expires'
            : 'ON DUPLICATE KEY UPDATE payload = VALUES(payload), expires = VALUES(expires)';
        $parameters = [];
        foreach ($rows as [$kind, $key, $payload, $expires]) {
            array_push($parameters, $this->namespace, $kind, $key, $payload, $expires);
        }

        return $this->pdo->prepare(
            "INSERT INTO {$this->table} (namespace, kind, cache_key, payload, expires) VALUES {$values} {$suffix}",
        )->execute($parameters);
    }

    private function upsertGenericRows(array $rows): bool
    {
        $this->pdo->beginTransaction();
        try {
            foreach ($rows as [$kind, $key, $payload, $expires]) {
                $update = $this->pdo->prepare(
                    "UPDATE {$this->table} SET payload = ?, expires = ? WHERE namespace = ? AND kind = ? AND cache_key = ?",
                );
                $update->execute([$payload, $expires, $this->namespace, $kind, $key]);
                if ($update->rowCount() === 0) {
                    $exists = $this->pdo->prepare(
                        "SELECT 1 FROM {$this->table} WHERE namespace = ? AND kind = ? AND cache_key = ?",
                    );
                    $exists->execute([$this->namespace, $kind, $key]);
                    if ($exists->fetchColumn() !== false) {
                        continue;
                    }
                    $this->pdo->prepare(
                        "INSERT INTO {$this->table} (namespace, kind, cache_key, payload, expires) VALUES (?, ?, ?, ?, ?)",
                    )->execute([$this->namespace, $kind, $key, $payload, $expires]);
                }
            }
            return $this->pdo->commit();
        } catch (PDOException $failure) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $failure;
        }
    }

    private function upsertRows(array $rows): bool
    {
        foreach (array_chunk($rows, self::BATCH_SIZE) as $chunk) {
            if (!$this->upsertChunk($chunk)) {
                return false;
            }
        }

        return true;
    }
}
