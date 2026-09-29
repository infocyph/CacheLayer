<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Integration\Runwire;

use Infocyph\Runwire\Coroutine\CoroutineScope;
use Infocyph\Runwire\RequestContext;
use Infocyph\Runwire\Runtime\Enum\RuntimeCapability;
use Infocyph\Runwire\RuntimeContext;
use LogicException;

final readonly class RunwireExecutionContext
{
    public function __construct(
        public RuntimeContext $runtime,
        public ?RequestContext $request = null,
        public ?CoroutineScope $scope = null,
    ) {
        if ($request !== null && $request->runtime() !== $runtime) {
            throw new LogicException('Runwire request context is bound to a different runtime.');
        }
    }

    public function supports(RuntimeCapability $capability): bool
    {
        return $this->runtime->supports($capability);
    }
}
