<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Bridge;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Contracts\Tenancy\TenantProvisioner;
use Glueful\Extensions\Tenancy\Models\Tenant;
use Glueful\Extensions\Tenancy\Models\TenantMembership;
use Glueful\Helpers\Utils;

/**
 * Binds the neutral TenantProvisioner contract over the extension's concrete Tenant /
 * TenantMembership registry models — the ONE place a consumer's provisioning path crosses into
 * the concrete models. Consumers depend only on the contract; the models never leak out.
 *
 * Both models are CENTRAL (never tenant-scoped): `status` (Tenant + membership) and `role`
 * (membership) are NOT fillable — a fresh create() defaults them via the migration
 * (status='active', role='member'). We set role='owner' with a raw update after create.
 *
 * Idempotent by uuid: an existing tenant with $tenantUuid short-circuits; the owner membership
 * is likewise created only when absent, so a crash-then-retry never duplicates rows.
 */
final class ContractTenantProvisioner implements TenantProvisioner
{
    public function provisionDefault(
        ApplicationContext $context,
        string $tenantUuid,
        string $slug,
        string $name,
        string $ownerUserUuid
    ): string {
        $existing = Tenant::query($context)->where('uuid', $tenantUuid)->first();
        if ($existing === null) {
            Tenant::create($context, [
                'uuid' => $tenantUuid,
                'slug' => $slug,
                'name' => $name,
            ]);
            // status is not fillable; the migration default is already 'active', but pin it
            // explicitly so provisioning never depends on the schema default.
            db($context)->table('tenants')->where('uuid', $tenantUuid)->update(['status' => 'active']);
        }

        $this->ensureOwnerMembership($context, $tenantUuid, $ownerUserUuid);

        return $tenantUuid;
    }

    public function hasAnyTenant(ApplicationContext $context): bool
    {
        return Tenant::query($context)->first() !== null;
    }

    private function ensureOwnerMembership(
        ApplicationContext $context,
        string $tenantUuid,
        string $ownerUserUuid
    ): void {
        $existing = TenantMembership::query($context)
            ->where('tenant_uuid', $tenantUuid)
            ->where('user_uuid', $ownerUserUuid)
            ->first();
        if ($existing !== null) {
            return;
        }

        $membership = TenantMembership::create($context, [
            'uuid' => Utils::generateNanoID(12),
            'tenant_uuid' => $tenantUuid,
            'user_uuid' => $ownerUserUuid,
        ]);

        // role/status are not fillable; raise the fresh member to an active owner.
        db($context)->table('tenant_memberships')
            ->where('uuid', $membership->uuid)
            ->update(['role' => 'owner', 'status' => 'active']);
    }
}
