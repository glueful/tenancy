<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Events;

use Glueful\Events\Contracts\BaseEvent;

/** A domain crossed its ownership-proof grace boundary and was revoked. */
final class DomainRevoked extends BaseEvent
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
