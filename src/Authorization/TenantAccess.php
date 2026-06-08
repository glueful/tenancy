<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Authorization;

use Glueful\Auth\UserIdentity;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Permissions\Context as GateContext;
use Glueful\Permissions\Gate;
use Glueful\Permissions\Vote;

/**
 * Decides whether a principal may bypass tenant-membership checks (a cross-tenant
 * "forAnyTenant" principal — e.g. a platform admin or support agent).
 *
 * Injectable collaborator with a single, intentionally narrow seam so enforcement code depends
 * on this contract rather than the Gate directly. It is non-final so tests can subclass it to
 * force a decision. The body asks the framework authorization {@see Gate} whether the user
 * holds ANY of the configured bypass permissions and fails CLOSED whenever authorization
 * cannot be evaluated.
 */
class TenantAccess
{
    /** @var array<int, string> */
    private const DEFAULT_BYPASS_PERMISSIONS = ['tenancy.access_any', 'tenancy.manage'];

    /**
     * Whether the given (authenticated) user may bypass per-tenant membership checks.
     *
     * Fails closed: a null user, an unresolvable Gate, or a Gate that grants none of the
     * configured bypass permissions all return false. Returns true iff the Gate decides GRANT
     * for at least one of config('tenancy.bypass_permissions').
     */
    public function canBypass(ApplicationContext $context, ?string $userUuid): bool
    {
        if ($userUuid === null) {
            return false;
        }

        $gate = $this->resolveGate($context);
        if ($gate === null) {
            // Never grant bypass when authorization cannot be evaluated.
            return false;
        }

        $permissions = $this->bypassPermissions($context);
        $identity = new UserIdentity($userUuid);
        $tenantId = $this->currentTenantUuid($context);

        foreach ($permissions as $permission) {
            $gateContext = new GateContext(tenantId: $tenantId);
            if ($gate->decide($identity, $permission, null, $gateContext) === Vote::GRANT) {
                return true;
            }
        }

        return false;
    }

    /**
     * The configured bypass permissions, defaulting to the platform-admin set.
     *
     * @return array<int, string>
     */
    private function bypassPermissions(ApplicationContext $context): array
    {
        $configured = config($context, 'tenancy.bypass_permissions', self::DEFAULT_BYPASS_PERMISSIONS);

        if (!is_array($configured) || $configured === []) {
            return self::DEFAULT_BYPASS_PERMISSIONS;
        }

        return array_values(array_filter($configured, 'is_string'));
    }

    /**
     * Resolve the authorization Gate from the container, or null when it is not available.
     */
    private function resolveGate(ApplicationContext $context): ?Gate
    {
        if (!$context->hasContainer()) {
            return null;
        }

        $container = $context->getContainer();

        try {
            if (!$container->has(Gate::class)) {
                return null;
            }
            $gate = $container->get(Gate::class);
        } catch (\Throwable) {
            return null;
        }

        return $gate instanceof Gate ? $gate : null;
    }

    /**
     * The currently active tenant uuid (best effort) for the Gate context, or null.
     */
    private function currentTenantUuid(ApplicationContext $context): ?string
    {
        $tenant = $context->getRequestState('tenancy.tenant');

        return is_object($tenant) && property_exists($tenant, 'uuid') && is_string($tenant->uuid)
            ? $tenant->uuid
            : null;
    }
}
