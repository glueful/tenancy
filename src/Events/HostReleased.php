<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Events;

use Glueful\Events\Contracts\BaseEvent;

final class HostReleased extends BaseEvent
{
    public function __construct(
        public readonly string $host,
        public readonly string $tenantUuid,
        public readonly string $retainedUntil,
    ) {
        parent::__construct();
    }
}
