<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cache\Adapter;

use PDO;
use PDOException;
use RuntimeException;

/** @internal */
final class PdoTagGenerationStore
{
    private const int BATCH_SIZE = 250;

    private const string KIND_TAG = 'tag';

    /**
     * @param list<string> $tags
     * @return array<string, string>
     */
    public static function getOrInitialize(
        PDO $pdo,
        string $driver,
        string $table,
        string $namespace,
        array $tags,
    ): array {
        if ($tags === []) {
            return [];
        }

        $stored = self::fetch($pdo, $table, $namespace, $tags);
        $generations = [];
        $missing = [];
        foreach ($tags as $tag) {
            $generation = self::normalize($stored[$tag] ?? null);
            if ($generation !== null) {
                $generations[$tag] = $generation;

                continue;
            }
            if (array_key_exists($tag, $stored)) {
                throw new RuntimeException('PDO tag generation contains invalid state.');
            }
            $missing[$tag] = bin2hex(random_bytes(16));
        }

        foreach ($missing as $tag => $candidate) {
            self::insertIfMissing($pdo, $driver, $table, $namespace, $tag, $candidate);
        }
        if ($missing === []) {
            return $generations;
        }

        $actual = self::fetch($pdo, $table, $namespace, array_keys($missing));
        foreach ($missing as $tag => $_candidate) {
            $generation = self::normalize($actual[$tag] ?? null);
            if ($generation === null) {
                throw new RuntimeException('Unable to initialize PDO tag generation.');
            }
            $generations[$tag] = $generation;
        }

        return $generations;
    }

    /**
     * @param list<string> $tags
     * @return array<string, string>
     */
    private static function fetch(PDO $pdo, string $table, string $namespace, array $tags): array
    {
        $stored = [];
        foreach (array_chunk($tags, self::BATCH_SIZE) as $chunk) {
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            $statement = $pdo->prepare(
                "SELECT cache_key, payload FROM {$table} "
                . "WHERE namespace = ? AND kind = ? AND cache_key IN ({$marks})",
            );
            $statement->execute([$namespace, self::KIND_TAG, ...$chunk]);
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (!is_array($row) || !is_string($row['cache_key'] ?? null)) {
                    continue;
                }
                $payload = $row['payload'] ?? null;
                if (is_resource($payload)) {
                    $payload = stream_get_contents($payload);
                }
                if (is_string($payload)) {
                    $stored[$row['cache_key']] = $payload;
                }
            }
        }

        return $stored;
    }

    private static function insertIfMissing(
        PDO $pdo,
        string $driver,
        string $table,
        string $namespace,
        string $tag,
        string $generation,
    ): void {
        $sql = match ($driver) {
            'pgsql', 'sqlite' => "INSERT INTO {$table} "
                . '(namespace, kind, cache_key, payload, expires) VALUES (?, ?, ?, ?, NULL) '
                . 'ON CONFLICT(namespace, kind, cache_key) DO NOTHING',
            'mysql', 'mariadb' => "INSERT INTO {$table} "
                . '(namespace, kind, cache_key, payload, expires) VALUES (?, ?, ?, ?, NULL) '
                . 'ON DUPLICATE KEY UPDATE cache_key = cache_key',
            default => "INSERT INTO {$table} "
                . '(namespace, kind, cache_key, payload, expires) VALUES (?, ?, ?, ?, NULL)',
        };

        try {
            $pdo->prepare($sql)->execute([$namespace, self::KIND_TAG, $tag, $generation]);
        } catch (PDOException $failure) {
            if (in_array($driver, ['pgsql', 'sqlite', 'mysql', 'mariadb'], true)) {
                throw $failure;
            }
            if (self::normalize(self::fetch($pdo, $table, $namespace, [$tag])[$tag] ?? null) === null) {
                throw $failure;
            }
        }
    }

    private static function normalize(mixed $value): ?string
    {
        return is_string($value) && strlen($value) === 32 && ctype_xdigit($value)
            ? strtolower($value)
            : null;
    }
}
