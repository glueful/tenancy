<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Tests\Integration;

use Glueful\Helpers\Utils;
use Glueful\Extensions\Tenancy\Models\Tenant;
use Glueful\Extensions\Tenancy\Models\TenantMembership;
use Glueful\Extensions\Tenancy\Tests\Support\TenancyTestCase;

/**
 * DB-backed tests for the central (non-tenant-scoped) registry models.
 *
 * Uses the shared {@see TenancyTestCase} harness: a bare in-memory SQLite Connection with
 * the tenancy migrations run against its schema builder, wrapped in a PSR-11 container
 * exposed as 'database' on an ApplicationContext so the context-first ORM static API
 * (Model::create($ctx, ...), Model::query($ctx), etc.) resolves the connection.
 */
final class TenantModelTest extends TenancyTestCase
{
    public function test_tenant_create_find_by_slug_and_is_active(): void
    {
        $created = $this->makeActiveTenant('acme', 'Acme');

        $this->assertTrue($created->exists);

        $found = Tenant::findBySlug($this->appContext(), 'acme');

        $this->assertInstanceOf(Tenant::class, $found);
        $this->assertSame('acme', $found->slug);
        $this->assertSame('Acme', $found->name);
        $this->assertTrue($found->isActive());

        $this->assertNull(Tenant::findBySlug($this->appContext(), 'does-not-exist'));
    }

    public function test_inactive_tenant_is_not_active(): void
    {
        $created = $this->makeActiveTenant('dormant', 'Dormant');
        $this->connection()->table('tenants')
            ->where('uuid', $created->uuid)
            ->update(['status' => 'suspended']);

        $tenant = Tenant::findBySlug($this->appContext(), 'dormant');

        $this->assertInstanceOf(Tenant::class, $tenant);
        $this->assertFalse($tenant->isActive());
    }

    public function test_tenant_status_is_not_mass_assignable(): void
    {
        $tenant = Tenant::create($this->appContext(), [
            'uuid' => Utils::generateNanoID(12),
            'slug' => 'status-payload',
            'name' => 'Status Payload',
            'status' => 'suspended',
        ]);

        $row = $this->connection()->table('tenants')->where('uuid', $tenant->uuid)->first();

        $this->assertSame('active', $row['status']);
    }

    public function test_membership_for_user_returns_active_membership(): void
    {
        $tenant = $this->makeActiveTenant('globex', 'Globex');

        $userUuid = Utils::generateNanoID(12);

        $this->makeMembership($tenant->uuid, $userUuid, 'admin', 'active');

        // An inactive membership in another tenant for the same user must be filtered out.
        $otherTenant = $this->makeActiveTenant('initech', 'Initech');
        $this->makeMembership($otherTenant->uuid, $userUuid, 'member', 'inactive');

        $memberships = TenantMembership::forUser($this->appContext(), $userUuid)
            ->active()
            ->get();

        $this->assertCount(1, $memberships);
        $membership = $memberships->first();
        $this->assertSame($tenant->uuid, $membership->tenant_uuid);
        $this->assertSame('admin', $membership->role);
        $this->assertSame('active', $membership->status);
    }

    public function test_membership_role_and_status_are_not_mass_assignable(): void
    {
        $tenant = $this->makeActiveTenant('membership-payload');
        $userUuid = Utils::generateNanoID(12);

        $membership = TenantMembership::create($this->appContext(), [
            'uuid' => Utils::generateNanoID(12),
            'tenant_uuid' => $tenant->uuid,
            'user_uuid' => $userUuid,
            'role' => 'admin',
            'status' => 'inactive',
        ]);

        $row = $this->connection()->table('tenant_memberships')->where('uuid', $membership->uuid)->first();

        $this->assertSame('member', $row['role']);
        $this->assertSame('active', $row['status']);
    }

    public function test_tenant_is_central_no_tenant_scoping(): void
    {
        // Three tenants with distinct uuids — there is NO tenant_uuid predicate injected
        // for the central registry, so all rows come back regardless of any tenant context.
        foreach (['one', 'two', 'three'] as $slug) {
            $this->makeActiveTenant($slug);
        }

        $all = Tenant::all($this->appContext());

        $this->assertCount(3, $all);
    }
}
