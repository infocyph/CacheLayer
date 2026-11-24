<?php

declare(strict_types=1);

namespace Infocyph\CacheLayer\Cluster\Event;

enum InvalidationEventType: string
{
    case Key = 'key';

    case NamespaceClear = 'namespace';

    case Tag = 'tag';
}
