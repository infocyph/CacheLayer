<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Memoize;

/** @internal */
final class MemoizerRuntime
{
    private static bool $bypass = false;

    public static function bypass(): bool
    {
        return self::$bypass;
    }

    public static function configure(bool $bypass): void
    {
        self::$bypass = $bypass;
    }

    public static function reset(): void
    {
        self::$bypass = false;
    }
}
