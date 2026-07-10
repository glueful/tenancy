<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Tests\Integration\Bridge;

use Glueful\Extensions\Contracts\Tenancy\TenantProvisioner;
use Glueful\Extensions\Tenancy\Bridge\ContractTenantProvisioner;
use Glueful\Extensions\Tenancy\Models\Tenant;
use Glueful\Extensions\Tenancy\Models\TenantMembership;
use Glueful\Extensions\Tenancy\Tests\Support\TenancyTestCase;

final class ContractTenantProvisionerTest extends TenancyTestCase
{
    private function provisioner(): TenantProvisioner
    {
        return new ContractTenantProvisioner();
    }

    public function testProvisionCreatesTenantAndOwnerMembership(): void
    {
        $returned = $this->provisioner()->provisionDefault(
            $this->appContext(),
            'tenant000001',
            'acme',
            'Acme',
            'user00000001',
        );

        self::assertSame('tenant000001', $returned);

        $tenant = Tenant::query($this->appContext())->where('uuid', 'tenant000001')->first();
        self::assertNotNull($tenant);
        self::assertSame('acme', $tenant->slug);
        self::assertSame('Acme', $tenant->name);
        self::assertSame('active', $tenant->status);

        $membership = TenantMembership::query($this->appContext())
            ->where('tenant_uuid', 'tenant000001')
            ->where('user_uuid', 'user00000001')
            ->first();
        self::assertNotNull($membership);
        self::assertSame('owner', $membership->role);
        self::assertSame('active', $membership->status);
    }

    public function testProvisionIsIdempotentByUuid(): void
    {
        $ctx = $this->appContext();

        $first = $this->provisioner()->provisionDefault($ctx, 'tenant000001', 'acme', 'Acme', 'user00000001');
        $second = $this->provisioner()->provisionDefault($ctx, 'tenant000001', 'acme', 'Acme', 'user00000001');

        self::assertSame($first, $second);

        $tenantCount = $this->connection()->table('tenants')->where('uuid', 'tenant000001')->count();
        self::assertSame(1, $tenantCount);

        $membershipCount = $this->connection()->table('tenant_memberships')
            ->where('tenant_uuid', 'tenant000001')
            ->where('user_uuid', 'user00000001')
            ->count();
        self::assertSame(1, $membershipCount);
    }

    public function testHasAnyTenantReflectsRegistryState(): void
    {
        $ctx = $this->appContext();

        self::assertFalse($this->provisioner()->hasAnyTenant($ctx));

        $this->provisioner()->provisionDefault($ctx, 'tenant000001', 'acme', 'Acme', 'user00000001');

        self::assertTrue($this->provisioner()->hasAnyTenant($ctx));
    }
}
