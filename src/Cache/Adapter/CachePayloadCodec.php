<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cache\Adapter;

use Closure;
use DateTimeImmutable;
use DateTimeInterface;
use Infocyph\CacheLayer\Cache\CacheOptions;
use Infocyph\CacheLayer\Cache\CacheRecord;
use Infocyph\CacheLayer\Cache\Item\CacheItem;
use Infocyph\CacheLayer\Serializer\ClosureSerializer;
use InvalidArgumentException;
use Psr\Cache\CacheItemInterface;
use RuntimeException;
use Throwable;

final readonly class CachePayloadCodec
{
    private const string COMPRESSED_PREFIX = 'cl2-gz:';

    private const string PLAIN_PREFIX = 'cl2:';

    private const string SIGNED_PREFIX = 'cl2-sig:';

    public function __construct(private CacheOptions $options = new CacheOptions()) {}

    /** @return array{ttl:int|null,expiresAt:int|null} */
    public static function expirationFromItem(CacheItemInterface $item): array
    {
        $ttl = $item instanceof CacheItem ? $item->ttlSeconds() : null;

        return [
            'ttl' => $ttl,
            'expiresAt' => $ttl === null ? null : time() + $ttl,
        ];
    }

    public static function isExpired(?int $expiresAt, ?int $now = null): bool
    {
        return $expiresAt !== null && $expiresAt <= ($now ?? time());
    }

    public static function toDateTime(?int $expiresAt): ?DateTimeInterface
    {
        return $expiresAt === null ? null : (new DateTimeImmutable())->setTimestamp($expiresAt);
    }

    public function decode(string $blob): ?CacheRecord
    {
        if ($this->isPayloadTooLarge($blob)) {
            return null;
        }

        $verified = $this->verifyAndExtractSignature($blob);
        if ($verified === null) {
            return null;
        }

        $serialized = $this->expandPayload($verified);
        if ($serialized === null || $this->isPayloadTooLarge($serialized)) {
            return null;
        }

        try {
            $decoded = $this->unserializeNative($serialized);
        } catch (Throwable) {
            return null;
        }

        return $this->normalizeRecord($decoded);
    }

    /**
     * @param array<string, string> $tags
     */
    public function encode(
        mixed $value,
        ?int $expiresAt,
        array $tags = [],
        ?string $namespaceGeneration = null,
    ): string {
        [$encoding, $encodedValue] = $this->encodeValue($value);
        $serialized = serialize([
            'format' => 2,
            'encoding' => $encoding,
            'value' => $encodedValue,
            'expires' => $expiresAt,
            'tags' => $tags,
            'namespace' => $namespaceGeneration,
        ]);
        if ($this->isPayloadTooLarge($serialized)) {
            throw new RuntimeException('The encoded cache record exceeds the configured payload limit.');
        }

        $payload = self::PLAIN_PREFIX . $serialized;
        $threshold = $this->options->compressionThreshold;
        if ($threshold !== null && strlen($serialized) >= $threshold && function_exists('gzencode')) {
            $compressed = gzencode($serialized, $this->options->compressionLevel);
            if (is_string($compressed) && strlen($compressed) < strlen($serialized)) {
                $payload = self::COMPRESSED_PREFIX . base64_encode($compressed);
            }
        }

        $encoded = $this->attachSignature($payload);
        if ($this->isPayloadTooLarge($encoded)) {
            throw new RuntimeException('The stored cache payload exceeds the configured payload limit.');
        }

        return $encoded;
    }

    private function assertNativeValueSupported(mixed $value): void
    {
        if ($value instanceof Closure) {
            throw new InvalidArgumentException('Closures must be cached as top-level values.');
        }
        if (is_resource($value)) {
            throw new InvalidArgumentException('Resource cache values are not supported.');
        }
        if (is_object($value) && !$this->options->allowObjects) {
            throw new InvalidArgumentException('Object cache values are disabled by security policy.');
        }
        if (!is_array($value)) {
            return;
        }
        foreach ($value as $item) {
            $this->assertNativeValueSupported($item);
        }
    }

    private function attachSignature(string $payload): string
    {
        if ($this->options->integrityKey === null) {
            return $payload;
        }

        $signature = hash_hmac('sha256', $payload, $this->options->integrityKey);

        return self::SIGNED_PREFIX . $signature . ':' . $payload;
    }

    private function containsUnsupportedDecodedValue(mixed $value): bool
    {
        if ($value instanceof Closure || is_resource($value)) {
            return true;
        }
        if (is_object($value)) {
            return !$this->options->allowObjects;
        }
        if (!is_array($value)) {
            return false;
        }
        foreach ($value as $item) {
            if ($this->containsUnsupportedDecodedValue($item)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<mixed> $record
     * @return array{valid:bool, value:mixed}
     */
    private function decodeValue(array $record): array
    {
        $encoding = $record['encoding'] ?? null;
        $value = $record['value'] ?? null;
        if ($encoding === 'closure') {
            if (!$this->options->allowClosures || !is_string($value)) {
                return ['valid' => false, 'value' => null];
            }

            try {
                return ['valid' => true, 'value' => ClosureSerializer::unserialize($value)];
            } catch (Throwable) {
                return ['valid' => false, 'value' => null];
            }
        }
        if ($encoding !== 'native' || $this->containsUnsupportedDecodedValue($value)) {
            return ['valid' => false, 'value' => null];
        }

        return ['valid' => true, 'value' => $value];
    }

    /** @return array{0:'closure'|'native', 1:mixed} */
    private function encodeValue(mixed $value): array
    {
        if ($value instanceof Closure) {
            if (!$this->options->allowClosures) {
                throw new InvalidArgumentException('Closure cache values are disabled by security policy.');
            }

            return ['closure', ClosureSerializer::serialize($value)];
        }

        $this->assertNativeValueSupported($value);

        return ['native', $value];
    }

    private function expandPayload(string $payload): ?string
    {
        if (str_starts_with($payload, self::PLAIN_PREFIX)) {
            return substr($payload, strlen(self::PLAIN_PREFIX));
        }
        if (!str_starts_with($payload, self::COMPRESSED_PREFIX)) {
            return null;
        }

        $compressed = base64_decode(substr($payload, strlen(self::COMPRESSED_PREFIX)), true);
        if (!is_string($compressed) || !function_exists('gzdecode')) {
            return null;
        }

        $maximumLength = $this->options->maxPayloadBytes === null
            ? 0
            : min($this->options->maxPayloadBytes, PHP_INT_MAX - 1) + 1;
        set_error_handler(static fn(): bool => true);

        try {
            $expanded = gzdecode($compressed, $maximumLength);
        } finally {
            restore_error_handler();
        }

        return is_string($expanded) ? $expanded : null;
    }

    private function isPayloadTooLarge(string $payload): bool
    {
        return $this->options->maxPayloadBytes !== null
            && strlen($payload) > $this->options->maxPayloadBytes;
    }

    private function normalizeRecord(mixed $decoded): ?CacheRecord
    {
        if (!is_array($decoded) || ($decoded['format'] ?? null) !== 2 || !array_key_exists('value', $decoded)) {
            return null;
        }

        $expiresAt = $decoded['expires'] ?? null;
        if ($expiresAt !== null && !is_int($expiresAt)) {
            return null;
        }

        $tags = $decoded['tags'] ?? null;
        if (!is_array($tags)) {
            return null;
        }
        $namespaceGeneration = $decoded['namespace'] ?? null;
        if ($namespaceGeneration !== null
            && (!is_string($namespaceGeneration)
                || strlen($namespaceGeneration) !== 32
                || !ctype_xdigit($namespaceGeneration))) {
            return null;
        }

        $value = $this->decodeValue($decoded);
        if (!$value['valid']) {
            return null;
        }

        foreach ($tags as $tag => $generation) {
            if (!is_string($tag)
                || !is_string($generation)
                || strlen($generation) !== 32
                || !ctype_xdigit($generation)) {
                return null;
            }
        }

        return new CacheRecord($value['value'], $expiresAt, $tags, $namespaceGeneration);
    }

    private function unserializeNative(string $payload): mixed
    {
        set_error_handler(static fn(): bool => true);

        try {
            return unserialize($payload, [
                'allowed_classes' => $this->options->allowObjects,
                'max_depth' => 128,
            ]);
        } finally {
            restore_error_handler();
        }
    }

    private function verifyAndExtractSignature(string $blob): ?string
    {
        if (!str_starts_with($blob, self::SIGNED_PREFIX)) {
            return $this->options->integrityKey === null ? $blob : null;
        }
        if ($this->options->integrityKey === null) {
            return null;
        }

        $separator = strpos($blob, ':', strlen(self::SIGNED_PREFIX));
        if ($separator === false) {
            return null;
        }

        $signature = substr($blob, strlen(self::SIGNED_PREFIX), $separator - strlen(self::SIGNED_PREFIX));
        $payload = substr($blob, $separator + 1);
        if (strlen($signature) !== 64 || !ctype_xdigit($signature)) {
            return null;
        }

        $expected = hash_hmac('sha256', $payload, $this->options->integrityKey);

        return hash_equals($expected, strtolower($signature)) ? $payload : null;
    }
}
