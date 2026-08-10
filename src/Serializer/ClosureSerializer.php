<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Serializer;

use Closure;
use InvalidArgumentException;
use Throwable;

use function Opis\Closure\serialize as opis_serialize;
use function Opis\Closure\unserialize as opis_unserialize;

final class ClosureSerializer
{
    private const string PREFIX = 'cls1:';

    public static function isSerialized(string $payload): bool
    {
        if (!str_starts_with($payload, self::PREFIX)) {
            return false;
        }

        $encoded = substr($payload, strlen(self::PREFIX));

        return $encoded !== '' && base64_decode($encoded, true) !== false;
    }

    public static function serialize(Closure $closure): string
    {
        return self::PREFIX . base64_encode(opis_serialize($closure));
    }

    public static function signed(string $key): SignedClosureSerializer
    {
        return new SignedClosureSerializer($key);
    }

    public static function unserialize(string $payload): Closure
    {
        if (!self::isSerialized($payload)) {
            throw new InvalidArgumentException('Invalid serialized Closure payload.');
        }

        $encoded = substr($payload, strlen(self::PREFIX));
        $serialized = base64_decode($encoded, true);
        if (!is_string($serialized)) {
            throw new InvalidArgumentException('Invalid serialized Closure payload.');
        }

        try {
            $closure = opis_unserialize($serialized);
        } catch (Throwable $failure) {
            throw new InvalidArgumentException('Unable to unserialize Closure payload.', 0, $failure);
        }
        if (!$closure instanceof Closure) {
            throw new InvalidArgumentException('Serialized payload does not contain a Closure.');
        }

        return $closure;
    }
}
