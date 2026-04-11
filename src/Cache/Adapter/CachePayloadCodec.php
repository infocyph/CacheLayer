<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cache\Adapter;

use DateTimeImmutable;
use DateTimeInterface;
use Infocyph\CacheLayer\Serializer\ValueSerializer;
use Psr\Cache\CacheItemInterface;
use Throwable;

final class CachePayloadCodec
{
    private const string COMPRESSED_PREFIX = 'imx-gz:';
    private const string FORMAT = 'imx-record-v1';
    private static int $compressionLevel = 6;
    private static ?int $compressionThresholdBytes = null;

    public static function configureCompression(?int $thresholdBytes = null, int $level = 6): void
    {
        self::$compressionThresholdBytes = $thresholdBytes === null ? null : max(1, $thresholdBytes);
        self::$compressionLevel = max(1, min(9, $level));
    }

    /**
     * @return array{value:mixed,expires:int|null}|null
     */
    public static function decode(string $blob): ?array
    {
        $decoded = self::tryUnserialize(self::expandIfCompressed($blob));
        if ($decoded === null) {
            return null;
        }

        $fromItem = self::decodeCacheItem($decoded);
        if ($fromItem !== null) {
            return $fromItem;
        }

        return self::decodeArrayPayload($decoded);
    }

    public static function encode(mixed $value, ?int $expiresAt): string
    {
        $encoded = ValueSerializer::serialize([
            '__imx_cache' => self::FORMAT,
            'value' => $value,
            'expires' => $expiresAt,
        ]);

        if (self::$compressionThresholdBytes === null || self::$compressionThresholdBytes < 1) {
            return $encoded;
        }

        if (strlen($encoded) < self::$compressionThresholdBytes || !function_exists('gzencode')) {
            return $encoded;
        }

        $compressed = gzencode($encoded, self::$compressionLevel);
        if (!is_string($compressed) || strlen($compressed) >= strlen($encoded)) {
            return $encoded;
        }

        return self::COMPRESSED_PREFIX . base64_encode($compressed);
    }

    /**
     * @return array{ttl:int|null,expiresAt:int|null}
     */
    public static function expirationFromItem(CacheItemInterface $item): array
    {
        $ttl = method_exists($item, 'ttlSeconds') ? $item->ttlSeconds() : null;
        $expiresAt = $ttl === null ? null : time() + $ttl;

        return ['ttl' => $ttl, 'expiresAt' => $expiresAt];
    }

    public static function isExpired(?int $expiresAt, ?int $now = null): bool
    {
        return $expiresAt !== null && $expiresAt <= ($now ?? time());
    }

    public static function toDateTime(?int $expiresAt): ?DateTimeInterface
    {
        return $expiresAt === null ? null : (new DateTimeImmutable())->setTimestamp($expiresAt);
    }

    /**
     * @return array{value:mixed,expires:int|null}|null
     */
    private static function decodeArrayPayload(mixed $decoded): ?array
    {
        if (!is_array($decoded)) {
            return null;
        }

        $fromFormatted = self::decodeFormattedPayload($decoded);
        if ($fromFormatted !== null) {
            return $fromFormatted;
        }

        if (array_key_exists('value', $decoded) && array_key_exists('expires', $decoded)) {
            return [
                'value' => $decoded['value'],
                'expires' => self::normalizeExpires($decoded['expires']),
            ];
        }

        return null;
    }

    /**
     * @return array{value:mixed,expires:int|null}|null
     */
    private static function decodeCacheItem(mixed $decoded): ?array
    {
        if (!$decoded instanceof CacheItemInterface) {
            return null;
        }

        return ['value' => $decoded->get(), 'expires' => null];
    }

    /**
     * @param array<string, mixed> $decoded
     * @return array{value:mixed,expires:int|null}|null
     */
    private static function decodeFormattedPayload(array $decoded): ?array
    {
        if (($decoded['__imx_cache'] ?? null) !== self::FORMAT || !array_key_exists('value', $decoded)) {
            return null;
        }

        return [
            'value' => $decoded['value'],
            'expires' => self::normalizeExpires($decoded['expires'] ?? null),
        ];
    }

    private static function expandIfCompressed(string $blob): string
    {
        if (!str_starts_with($blob, self::COMPRESSED_PREFIX)) {
            return $blob;
        }

        $payload = substr($blob, strlen(self::COMPRESSED_PREFIX));
        $raw = base64_decode($payload, true);
        if ($raw === false || !function_exists('gzdecode')) {
            return $blob;
        }

        $decoded = gzdecode($raw);
        return is_string($decoded) ? $decoded : $blob;
    }

    private static function normalizeExpires(mixed $expires): ?int
    {
        return is_int($expires) ? $expires : null;
    }

    private static function tryUnserialize(string $blob): mixed
    {
        try {
            return ValueSerializer::unserialize($blob);
        } catch (Throwable) {
            return null;
        }
    }
}
