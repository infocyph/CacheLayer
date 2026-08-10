<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Serializer;

use Closure;
use InvalidArgumentException;

final readonly class SignedClosureSerializer
{
    private const string PREFIX = 'cls1-sig:';

    public function __construct(private string $key)
    {
        if ($key === '') {
            throw new InvalidArgumentException('The Closure signing key must not be empty.');
        }
    }

    public function serialize(Closure $closure): string
    {
        $payload = ClosureSerializer::serialize($closure);
        $signature = hash_hmac('sha256', $payload, $this->key);

        return self::PREFIX . $signature . ':' . $payload;
    }

    public function unserialize(string $payload): Closure
    {
        if (!str_starts_with($payload, self::PREFIX)) {
            throw new InvalidArgumentException('Invalid signed Closure payload.');
        }

        $separator = strpos($payload, ':', strlen(self::PREFIX));
        if ($separator === false) {
            throw new InvalidArgumentException('Invalid signed Closure payload.');
        }

        $signature = substr($payload, strlen(self::PREFIX), $separator - strlen(self::PREFIX));
        $closurePayload = substr($payload, $separator + 1);
        $expected = hash_hmac('sha256', $closurePayload, $this->key);
        if (strlen($signature) !== 64 || !ctype_xdigit($signature) || !hash_equals($expected, strtolower($signature))) {
            throw new InvalidArgumentException('Closure payload signature verification failed.');
        }

        return ClosureSerializer::unserialize($closurePayload);
    }
}
