<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Bridge;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Contracts\Tenancy\TenantProvisioningRunner;
use Glueful\Extensions\Tenancy\Bypass\Tenancy;
use Glueful\Extensions\Tenancy\Context\CurrentContext;
use Glueful\Extensions\Tenancy\Exceptions\TenantNotFoundException;
use Glueful\Extensions\Tenancy\Models\Tenant;

/** Scopes seed work to a committed provisioning tenant without making it publicly resolvable. */
final class ContractTenantProvisioningRunner implements TenantProvisioningRunner
{
    public function __construct(private readonly ApplicationContext $context)
    {
    }

    public function runAsProvisioningTenant(string $tenantUuid, callable $fn): mixed
    {
        $tenant = Tenant::query($this->context)->where('uuid', $tenantUuid)->first();
        if (!$tenant instanceof Tenant || !in_array($tenant->status, ['provisioning', 'active'], true)) {
            throw new TenantNotFoundException("Unknown provisioning tenant: {$tenantUuid}");
        }

        return $this->withContext(static fn (): mixed => Tenancy::runAsTenant($tenant, $fn));
    }

    private function withContext(callable $fn): mixed
    {
        if (CurrentContext::get() !== null) {
            return $fn();
        }
        CurrentContext::set($this->context);
        try {
            return $fn();
        } finally {
            CurrentContext::clear();
        }
    }
}
