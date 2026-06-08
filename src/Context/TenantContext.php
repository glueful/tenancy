<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Context;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Tenancy\Models\Tenant;

/**
 * Request-scoped holder for the currently active tenant and bypass mode.
 *
 * State lives in {@see ApplicationContext::requestState} (NOT a static/singleton), so it is
 * naturally isolated per request and reset with the rest of the request state. Two keys are
 * used: 'tenancy.tenant' (the current {@see Tenant}) and 'tenancy.bypass' (a bypass-mode
 * string consumed later by enforcement in Phase 6).
 */
final class TenantContext
{
    private const KEY_TENANT = 'tenancy.tenant';
    private const KEY_BYPASS = 'tenancy.bypass';

    public function __construct(private readonly ApplicationContext $context)
    {
    }

    /**
     * Set the currently active tenant.
     */
    public function setTenant(Tenant $tenant): void
    {
        $this->context->setRequestState(self::KEY_TENANT, $tenant);
    }

    /**
     * The currently active tenant, or null when none is set.
     */
    public function currentTenant(): ?Tenant
    {
        $tenant = $this->context->getRequestState(self::KEY_TENANT);

        return $tenant instanceof Tenant ? $tenant : null;
    }

    /**
     * The uuid of the currently active tenant, or null when none is set.
     */
    public function currentTenantUuid(): ?string
    {
        return $this->currentTenant()?->uuid;
    }

    /**
     * Whether a tenant is currently active.
     */
    public function hasTenant(): bool
    {
        return $this->currentTenant() !== null;
    }

    /**
     * Set (or clear, with null) the bypass mode.
     */
    public function setBypass(?string $mode): void
    {
        $this->context->setRequestState(self::KEY_BYPASS, $mode);
    }

    /**
     * The current bypass mode, or null when not bypassing.
     */
    public function bypassMode(): ?string
    {
        $mode = $this->context->getRequestState(self::KEY_BYPASS);

        return is_string($mode) ? $mode : null;
    }

    /**
     * Clear both the active tenant and the bypass mode.
     *
     * Only the two tenancy keys are nulled — this intentionally does NOT call
     * resetRequestState(), which would wipe unrelated request state.
     */
    public function clear(): void
    {
        $this->context->setRequestState(self::KEY_TENANT, null);
        $this->context->setRequestState(self::KEY_BYPASS, null);
    }
}
