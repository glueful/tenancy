<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Events;

use Glueful\Events\Contracts\BaseEvent;

/** A revoked domain proved ownership again and became resolvable. */
final class DomainReverified extends BaseEvent
{
    public function __construct(
        public readonly string $domainUuid,
        public readonly string $tenantUuid,
        public readonly string $host,
        public readonly string $outcome,
        public readonly int $consecutiveFailures,
        public readonly string $verificationStatus,
    ) {
        parent::__construct();
    }
}
