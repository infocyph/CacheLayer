<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Integration\Runwire;

use Infocyph\CacheLayer\Memoize\Memoizer;
use Infocyph\CacheLayer\Memoize\OnceMemoizer;
use Infocyph\Runwire\RequestContext;
use Infocyph\Runwire\Runtime\RequestResetterInterface;
use Infocyph\Runwire\RuntimeContext;
use LogicException;

final readonly class RunwireRequestResetter implements RequestResetterInterface
{
    public function __construct(private RuntimeContext $runtime) {}

    public function reset(RequestContext $context): void
    {
        if ($context->runtime() !== $this->runtime) {
            throw new LogicException('Runwire request resetter received a context from a different runtime.');
        }

        if (!$this->runtime->persistent || $this->runtime->concurrent) {
            return;
        }

        Memoizer::instance()->flush();
        OnceMemoizer::instance()->flush();
    }
}
