<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Membership;

use Glueful\Bootstrap\ApplicationContext;

final class ConfigRoleAuthority implements MembershipRoleAuthority
{
    public function isAssignable(ApplicationContext $context, string $tenantUuid, string $role): bool
    {
        $roles = config($context, 'tenancy.membership.roles', ['owner', 'admin', 'member', 'viewer']);
        return is_array($roles) && in_array($role, $roles, true);
    }
}
