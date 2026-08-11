<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Exceptions;

use InvalidArgumentException;
use Psr\Cache\InvalidArgumentException as PsrInvalidArgumentException;
use Psr\SimpleCache\InvalidArgumentException as SimpleCacheInvalidArgumentException;

/**
 * Thrown when a cache key or argument is invalid.
 */
class CacheInvalidArgumentException extends InvalidArgumentException implements PsrInvalidArgumentException, SimpleCacheInvalidArgumentException {}
