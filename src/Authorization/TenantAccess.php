<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Authorization;

use Glueful\Bootstrap\ApplicationContext;

/**
 * Decides whether a principal may bypass tenant-membership checks (a cross-tenant
 * "forAnyTenant" principal — e.g. a platform admin or support agent).
 *
 * Injectable collaborator with a single, intentionally narrow seam so enforcement code
 * depends on this contract rather than the Gate directly. It is non-final so tests can
 * subclass it to force a decision. Phase 6 will replace the body with the real Gate check.
 */
class TenantAccess
{
    /**
     * Whether the given (authenticated) user may bypass per-tenant membership checks.
     *
     * Unauthenticated principals ($userUuid === null) can never bypass. For now every
     * authenticated user is denied bypass until the Gate integration lands.
     */
    public function canBypass(ApplicationContext $context, ?string $userUuid): bool
    {
        if ($userUuid === null) {
            return false;
        }

        // TODO(Phase 6): check Gate for config('tenancy.bypass_permissions')
        return false;
    }
}
