<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Authorization;

use Glueful\Auth\UserIdentity;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Permissions\Context as GateContext;
use Glueful\Permissions\Gate;
use Glueful\Permissions\PermissionManager;
use Glueful\Permissions\Vote;

/**
 * Decides whether a principal may bypass tenant-membership checks (a cross-tenant
 * "forAnyTenant" principal — e.g. a platform admin or support agent).
 *
 * Injectable collaborator with a single, intentionally narrow seam so enforcement code depends
 * on this contract rather than the authorization stack directly. It is non-final so tests can
 * subclass it to force a decision. The body asks the framework authorization layer whether the
 * user holds ANY of the configured bypass permissions and fails CLOSED whenever authorization
 * cannot be evaluated.
 *
 * Resolution order, so provider-managed RBAC (e.g. the `glueful/aegis` extension) actually
 * governs bypass:
 *  1. The app's ACTIVE permission provider via {@see PermissionManager::can()} — the same
 *     authority the rest of the app uses, honoring the configured provider_mode. A bare
 *     {@see Gate::decide()} never sees provider-backed grants (the provider is only consulted
 *     when handed a providerDecide callback), and tenancy's UserIdentity carries no roles for
 *     the Gate's default voters to match — so the provider path is what makes real-app bypass
 *     work at all.
 *  2. Fallback to the {@see Gate} voters (super-roles / config policies) when no provider is
 *     active — covers config-only setups and the default install.
 */
class TenantAccess
{
    /** @var array<int, string> */
    private const DEFAULT_BYPASS_PERMISSIONS = ['tenancy.access_any', 'tenancy.manage'];

    /**
     * Whether the given (authenticated) user may bypass per-tenant membership checks.
     *
     * Fails closed: a null user, or an authorization stack that grants none of the configured
     * bypass permissions, all return false. Returns true iff the active permission provider OR
     * the Gate grants at least one of config('tenancy.bypass_permissions').
     */
    public function canBypass(ApplicationContext $context, ?string $userUuid): bool
    {
        if ($userUuid === null) {
            return false;
        }

        $permissions = $this->bypassPermissions($context);
        $tenantId = $this->currentTenantUuid($context);

        foreach ($permissions as $permission) {
            // 1) The app's active permission provider (provider-managed RBAC, e.g. aegis).
            if ($this->providerGrants($context, $userUuid, $permission, $tenantId)) {
                return true;
            }
            // 2) Fallback to the Gate's voters when no provider is active.
            if ($this->gateGrants($context, $userUuid, $permission, $tenantId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the app's ACTIVE permission provider grants the permission. Returns false (and
     * defers to the Gate) when no provider is active. Fails closed on any error.
     */
    private function providerGrants(
        ApplicationContext $context,
        string $userUuid,
        string $permission,
        ?string $tenantId
    ): bool {
        try {
            $manager = PermissionManager::getInstance(null, $context);
            if ($manager->getProvider() === null) {
                return false; // no active provider — defer to the Gate path
            }

            return $manager->can($userUuid, $permission, '', ['tenant_id' => $tenantId]);
        } catch (\Throwable) {
            // Never grant bypass when authorization cannot be evaluated.
            return false;
        }
    }

    /**
     * Whether the framework Gate (super-role / config-policy voters) grants the permission.
     * Returns false when the Gate is unavailable.
     */
    private function gateGrants(
        ApplicationContext $context,
        string $userUuid,
        string $permission,
        ?string $tenantId
    ): bool {
        $gate = $this->resolveGate($context);
        if ($gate === null) {
            return false;
        }

        $gateContext = new GateContext(tenantId: $tenantId);

        return $gate->decide(new UserIdentity($userUuid), $permission, null, $gateContext) === Vote::GRANT;
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
