<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Membership;

use Glueful\Bootstrap\ApplicationContext;

interface MembershipRoleLock
{
    public function lock(ApplicationContext $context, string $tenantUuid, string $role): void;

    /** @param list<string> $roles */
    public function lockMany(ApplicationContext $context, string $tenantUuid, array $roles): void;
}
