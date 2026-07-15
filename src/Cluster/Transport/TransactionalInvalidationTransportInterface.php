<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cluster\Transport;

use Infocyph\CacheLayer\Cluster\Event\InvalidationEvent;
use PDO;

interface TransactionalInvalidationTransportInterface extends InvalidationTransportInterface
{
    public function publishWithinTransaction(PDO $connection, InvalidationEvent $event): string;
}
