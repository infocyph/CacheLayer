<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cache\Adapter;

use Infocyph\CacheLayer\Cache\CacheInput;
use Infocyph\CacheLayer\Cache\Item\CacheItem;
use Infocyph\CacheLayer\Support\FilesystemTrust;
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
        #[\SensitiveParameter]
        ?string $dsn = null,
        ?string $username = null,
        #[\SensitiveParameter]
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
        self::assertSqliteTarget($resolvedDsn);
        if ($pdo instanceof PDO) {
            $this->pdo = $pdo;
        } else {
            try {
                $this->pdo = new PDO($resolvedDsn, $username, $password);
            } catch (PDOException) {
                throw new RuntimeException('Unable to connect to the PDO cache backend.');
            }
        }
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
        if (FilesystemTrust::containsSymlink($directory)) {
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
        $this->discardDeferredKey($key);

        $statement = $this->pdo->prepare(
            "DELETE FROM {$this->table} WHERE namespace = ? AND kind = ? AND cache_key = ?",
        );

        return $statement->execute([$this->namespace, self::KIND_DATA, $key]);
    }

    /** @param list<string> $keys */
    public function deleteItems(array $keys): bool
    {
        $this->discardDeferredKeys($keys);

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

        return $item ?? $this->genericMiss($key);
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
        $missing = [];
        foreach ($tags as $tag) {
            $row = $rows[$tag] ?? null;
            if (is_array($row)) {
                $generation = $row['payload'];
                if (!self::isGeneration($generation)) {
                    throw new RuntimeException('PDO tag generation contains invalid state.');
                }
                $generations[$tag] = strtolower($generation);

                continue;
            }
            $missing[$tag] = self::newGeneration();
        }
        foreach ($missing as $tag => $candidate) {
            if (!$this->insertTagGenerationIfMissing($tag, $candidate)) {
                throw new RuntimeException('Unable to initialize PDO tag generation.');
            }
        }
        if ($missing === []) {
            return $generations;
        }

        $actual = $this->fetchRows(self::KIND_TAG, array_keys($missing));
        foreach ($missing as $tag => $_candidate) {
            $generation = $actual[$tag]['payload'] ?? null;
            if (!self::isGeneration($generation)) {
                throw new RuntimeException('Unable to initialize PDO tag generation.');
            }
            $generations[$tag] = strtolower($generation);
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
        foreach ($keys as $key) {
            $row = $rows[$key] ?? null;
            $item = is_array($row) ? $this->hydrate($key, $row) : null;
            $items[$key] = $item ?? $this->genericMiss($key);
        }

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
        $cutoff = time();
        $statement->bindValue(3, $cutoff, PDO::PARAM_INT);
        $statement->bindValue(4, $limit, PDO::PARAM_INT);
        $statement->execute();
        $keys = $statement->fetchAll(PDO::FETCH_COLUMN);
        $keys = array_values(array_filter($keys, is_string(...)));

        return $this->deleteExpiredKeys($keys, $cutoff);
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


    private function insertTagGenerationIfMissing(string $tag, string $generation): bool
    {
        $sql = match ($this->driver) {
            'pgsql', 'sqlite' => "INSERT INTO {$this->table} "
                . '(namespace, kind, cache_key, payload, expires) VALUES (?, ?, ?, ?, NULL) '
                . 'ON CONFLICT(namespace, kind, cache_key) DO NOTHING',
            'mysql', 'mariadb' => "INSERT INTO {$this->table} "
                . '(namespace, kind, cache_key, payload, expires) VALUES (?, ?, ?, ?, NULL) '
                . 'ON DUPLICATE KEY UPDATE cache_key = cache_key',
            default => "INSERT INTO {$this->table} "
                . '(namespace, kind, cache_key, payload, expires) VALUES (?, ?, ?, ?, NULL)',
        };

        try {
            return $this->pdo->prepare($sql)->execute([
                $this->namespace,
                self::KIND_TAG,
                $tag,
                strtolower($generation),
            ]);
        } catch (PDOException $failure) {
            if (in_array($this->driver, ['pgsql', 'sqlite', 'mysql', 'mariadb'], true)) {
                throw $failure;
            }

            $row = $this->fetchRows(self::KIND_TAG, [$tag])[$tag] ?? null;

            return is_array($row) && self::isGeneration($row['payload']);
        }
    }

    private static function assertSqliteTarget(string $dsn): void
    {
        if (!str_starts_with($dsn, 'sqlite:')) {
            return;
        }

        $file = substr($dsn, strlen('sqlite:'));
        if ($file === '' || $file === ':memory:') {
            return;
        }
        if (FilesystemTrust::containsSymlink($file)) {
            throw new RuntimeException("Refusing symlinked SQLite cache path: {$file}");
        }
        if (file_exists($file) && !is_file($file)) {
            throw new RuntimeException("SQLite cache path is not a regular file: {$file}");
        }
    }


    /** @param list<string> $keys */
    private function deleteExpiredKeys(array $keys, int $cutoff): int
    {
        $deleted = 0;
        foreach (array_chunk($keys, self::BATCH_SIZE) as $chunk) {
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            $statement = $this->pdo->prepare(
                "DELETE FROM {$this->table} WHERE namespace = ? AND kind = ? "
                . "AND expires IS NOT NULL AND expires <= ? AND cache_key IN ({$marks})",
            );
            if (!$statement->execute([$this->namespace, self::KIND_DATA, $cutoff, ...$chunk])) {
                return $deleted;
            }
            $deleted += $statement->rowCount();
        }

        return $deleted;
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

        $record = $this->decodeRecordFromBlob($row['payload'], $key);

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
