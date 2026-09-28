<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Integration\Runwire;

use Infocyph\CacheLayer\Memoize\Memoizer;
use Infocyph\CacheLayer\Memoize\MemoizerRuntime;
use Infocyph\CacheLayer\Memoize\OnceMemoizer;
use Infocyph\Runwire\RuntimeContext;

final class RunwireIntegration
{
    private static ?RuntimeContext $runtime = null;

    public static function bind(RuntimeContext $runtime): RunwireRequestResetter
    {
        self::flushMemoizers();
        self::$runtime = $runtime;
        MemoizerRuntime::configure($runtime->persistent && $runtime->concurrent);

        return new RunwireRequestResetter($runtime);
    }

    public static function release(?RuntimeContext $runtime = null): void
    {
        if ($runtime !== null && self::$runtime !== $runtime) {
            return;
        }

        self::flushMemoizers();
        self::$runtime = null;
        MemoizerRuntime::reset();
    }

    public static function runtime(): ?RuntimeContext
    {
        return self::$runtime;
    }

    private static function flushMemoizers(): void
    {
        Memoizer::instance()->flush();
        OnceMemoizer::instance()->flush();
    }
}
