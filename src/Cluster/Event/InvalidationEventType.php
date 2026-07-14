<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cluster\Event;

enum InvalidationEventType: string
{
    case Key = 'key';

    case Namespace = 'namespace';

    case Tag = 'tag';
}
