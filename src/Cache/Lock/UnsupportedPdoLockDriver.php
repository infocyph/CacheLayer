<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cache\Lock;

use RuntimeException;

final class UnsupportedPdoLockDriver extends RuntimeException {}
