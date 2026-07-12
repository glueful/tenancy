<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Membership;

use Glueful\Bootstrap\ApplicationContext;

interface MembershipRoleAuthority
{
    public function isAssignable(ApplicationContext $context, string $tenantUuid, string $role): bool;
}
