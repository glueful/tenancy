<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Resolution\Resolvers;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Tenancy\Resolution\TenantResolverInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Resolves the user's selected active tenant.
 *
 * For v1 this reads the `tenancy.active_tenant` request attribute, which a later
 * UX/session concern (Phase 6) is responsible for setting.
 */
final class ActiveSessionResolver implements TenantResolverInterface
{
    public function resolve(Request $request, ApplicationContext $context): ?string
    {
        $value = $request->attributes->get('tenancy.active_tenant');

        return $value !== null ? (string) $value : null;
    }
}
