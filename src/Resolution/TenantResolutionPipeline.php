<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Resolution;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Tenancy\Authorization\TenantAccess;
use Glueful\Extensions\Tenancy\Context\TenantContext;
use Glueful\Extensions\Tenancy\Exceptions\TenantAccessDeniedException;
use Glueful\Extensions\Tenancy\Exceptions\TenantNotFoundException;
use Glueful\Extensions\Tenancy\Models\Tenant;
use Glueful\Extensions\Tenancy\Models\TenantMembership;
use Symfony\Component\HttpFoundation\Request;

/**
 * Resolves a tenant candidate from the request, then VALIDATES it before trusting it.
 *
 * This is the security boundary of the extension: a raw candidate (e.g. an X-Tenant-Id
 * header) is never authoritative on its own. The pipeline fails CLOSED —
 *
 *   - existence/active is checked BEFORE membership, so an unknown OR inactive tenant is a
 *     404 ({@see TenantNotFoundException}) and never leaks whether a tenant exists;
 *   - an authenticated principal who is not an active member (and has no bypass) gets a 403
 *     ({@see TenantAccessDeniedException});
 *   - a bypass-holding principal is marked 'forAnyTenant' and skips membership;
 *   - unauthenticated requests are not membership-checked here (auth is enforced upstream).
 */
final class TenantResolutionPipeline
{
    public function __construct(
        private ResolverChain $chain,
        private TenantAccess $access,
    ) {
    }

    /**
     * Resolve + validate the tenant and set {@see TenantContext}, or fail closed.
     *
     * @throws TenantNotFoundException     no candidate (when required) | unknown | inactive tenant
     * @throws TenantAccessDeniedException authenticated non-member without bypass
     */
    public function resolve(Request $request, ApplicationContext $context, bool $required): void
    {
        $tc = new TenantContext($context);

        $candidate = $this->chain->resolve($request, $context);

        if ($candidate === null) {
            if ($required) {
                throw new TenantNotFoundException('No tenant resolved');
            }

            // Central/optional route — leave the context empty.
            return;
        }

        // Dual lookup: candidates may be a stable uuid or a human-facing slug.
        $tenant = Tenant::query($context)->where('uuid', $candidate)->first();
        if ($tenant === null) {
            $tenant = Tenant::query($context)->where('slug', $candidate)->first();
        }
        if ($tenant === null) {
            throw new TenantNotFoundException('Unknown tenant');
        }

        // Inactive looks identical to nonexistent to a client (both 404).
        if (!$tenant->isActive()) {
            throw new TenantNotFoundException('Unknown tenant');
        }

        $userUuid = $request->attributes->get('auth.user.uuid');

        if ($userUuid !== null) {
            if ($this->access->canBypass($context, $userUuid)) {
                // Cross-tenant principal — allowed without a membership row.
                $tc->setBypass('forAnyTenant');
            } else {
                $membership = TenantMembership::query($context)
                    ->where('tenant_uuid', $tenant->uuid)
                    ->where('user_uuid', $userUuid)
                    ->where('status', 'active')
                    ->first();

                if ($membership === null) {
                    throw new TenantAccessDeniedException('Not a member of this tenant');
                }
            }
        }

        $tc->setTenant($tenant);
    }
}
