<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Events;

use Glueful\Events\Contracts\BaseEvent;

final class TenantDeleted extends BaseEvent
{
    public function __construct(
        public readonly string $tenantUuid,
        public readonly string $priorStatus,
        public readonly string $purgeAfter,
    ) {
        parent::__construct();
    }
}
