<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Exceptions;

use RuntimeException;

final class CacheBackendException extends RuntimeException implements \Psr\Cache\CacheException, \Psr\SimpleCache\CacheException {}
