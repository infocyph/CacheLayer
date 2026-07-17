<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cluster\Transport;

use Infocyph\CacheLayer\Cluster\Exception\ClusterTransportException;

final class EventField
{
    public static function requiredString(
        mixed $fields,
        string $name,
        string $invalidFieldsMessage,
        string $invalidValueMessage,
    ): string {
        if (!is_array($fields)) {
            throw new ClusterTransportException($invalidFieldsMessage);
        }

        $value = $fields[$name] ?? null;
        if (!is_string($value) || $value === '') {
            throw new ClusterTransportException($invalidValueMessage);
        }

        return $value;
    }
}
