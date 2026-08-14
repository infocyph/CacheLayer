<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cache;

use Infocyph\CacheLayer\Cache\Lock\LockProviderInterface;

/**
 * CacheLayer capability required for security-sensitive authentication state.
 */
interface AuthenticationStateCacheInterface extends CacheInterface
{
    public function authenticationStateLock(): ?LockProviderInterface;

    public function hasPayloadIntegrity(): bool;

    public function isAuthoritative(): bool;

    public function isFailOpen(): bool;
}
