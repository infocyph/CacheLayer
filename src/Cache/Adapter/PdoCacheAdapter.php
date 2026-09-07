<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cache\Adapter;

use Infocyph\CacheLayer\Cache\CacheInput;
use Infocyph\CacheLayer\Cache\Item\CacheItem;
use PDO;
use PDOException;
use Psr\Cache\CacheItemInterface;
use RuntimeException;

final class PdoCacheAdapter extends AbstractCacheAdapter implements ConditionalAtomicCachePoolInterface
{
    use PdoAtomicOperations;

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
        $statement = $this->pdo->prepare(
            "DELETE FROM {$this->table} WHERE namespace = ? AND kind = ? AND cache_key = ?",
        );

        return $statement->execute([$this->namespace, self::KIND_DATA, $key]);
    }

    /** @param list<string> $keys */
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

    /**
     * @param list<string> $tags
     * @return array<string, string>
     */
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

    /**
     * @param list<string> $keys
     * @return array<string, CacheItem>
     */
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

    /** @param list<string> $tags */
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

    /** @param array<string, CacheItemInterface> $items */
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
            $rows[] = [
                self::KIND_DATA,
                $item->getKey(),
                $this->encodeItem($item, $expiration['expiresAt']),
                $expiration['expiresAt'],
            ];
        }

        return $this->deleteByKind(self::KIND_DATA, $expired) && $this->upsertRows($rows);
    }

    /** @param list<string> $keys */
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

    /**
     * @param list<string> $keys
     * @return array<string, array{payload:string, expires:int|null}>
     */
    private function fetchRows(string $kind, array $keys): array
    {
        $rows = [];
        foreach (array_chunk($keys, self::BATCH_SIZE) as $chunk) {
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            $statement = $this->pdo->prepare(
                "SELECT cache_key, payload, expires FROM {$this->table} "
                . "WHERE namespace = ? AND kind = ? AND cache_key IN ({$marks})",
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

    /** @param array{payload:string, expires:int|null} $row */
    private function hydrate(string $key, array $row): ?CacheItem
    {
        if (CachePayloadCodec::isExpired($row['expires'])) {
            return null;
        }

        $record = $this->decodeRecordFromBlob($row['payload']);

        return $record === null ? null : $this->genericItemFromRecord($key, $record);
    }

    /** @param list<array{0:string, 1:string, 2:string, 3:int|null}> $rows */
    private function upsertChunk(array $rows): bool
    {
        if (!in_array($this->driver, ['pgsql', 'sqlite', 'mysql', 'mariadb'], true)) {
            return $this->upsertGenericRows($rows);
        }

        $values = implode(',', array_fill(0, count($rows), '(?, ?, ?, ?, ?)'));
        $suffix = in_array($this->driver, ['pgsql', 'sqlite'], true)
            ? 'ON CONFLICT (namespace, kind, cache_key) DO UPDATE SET '
                . 'payload = EXCLUDED.payload, expires = EXCLUDED.expires'
            : 'ON DUPLICATE KEY UPDATE payload = VALUES(payload), expires = VALUES(expires)';
        $parameters = [];
        foreach ($rows as [$kind, $key, $payload, $expires]) {
            array_push($parameters, $this->namespace, $kind, $key, $payload, $expires);
        }

        return $this->pdo->prepare(
            "INSERT INTO {$this->table} (namespace, kind, cache_key, payload, expires) "
            . "VALUES {$values} {$suffix}",
        )->execute($parameters);
    }

    /** @param list<array{0:string, 1:string, 2:string, 3:int|null}> $rows */
    private function upsertGenericRows(array $rows): bool
    {
        $this->pdo->beginTransaction();

        try {
            foreach ($rows as [$kind, $key, $payload, $expires]) {
                $update = $this->pdo->prepare(
                    "UPDATE {$this->table} SET payload = ?, expires = ? "
                    . 'WHERE namespace = ? AND kind = ? AND cache_key = ?',
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
                        "INSERT INTO {$this->table} (namespace, kind, cache_key, payload, expires) "
                        . 'VALUES (?, ?, ?, ?, ?)',
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

    /** @param list<array{0:string, 1:string, 2:string, 3:int|null}> $rows */
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
