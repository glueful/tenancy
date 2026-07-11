<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Events;

use Glueful\Events\Contracts\BaseEvent;

final class TenantRestored extends BaseEvent
{
    public function __construct(
        public readonly string $tenantUuid,
        public readonly string $restoredStatus,
    ) {
        parent::__construct();
    }
}
