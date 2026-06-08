<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Tests\Integration;

use Glueful\Database\Connection;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;
use Glueful\Migrations\CreateTenantMembershipsTable;
use Glueful\Migrations\CreateTenantsTable;
use PHPUnit\Framework\TestCase;

final class MigrationsTest extends TestCase
{
    private function schema(): SchemaBuilderInterface
    {
        $connection = new Connection([
            'engine' => 'sqlite',
            'sqlite' => ['primary' => ':memory:'],
            'pooling' => ['enabled' => false],
        ]);

        return $connection->getSchemaBuilder();
    }

    public function test_migrations_create_and_drop_registry_tables(): void
    {
        $schema = $this->schema();

        (new CreateTenantsTable())->up($schema);
        (new CreateTenantMembershipsTable())->up($schema);

        // Tables exist
        $this->assertTrue($schema->hasTable('tenants'), 'tenants table should exist');
        $this->assertTrue($schema->hasTable('tenant_memberships'), 'tenant_memberships table should exist');

        // Key columns on tenants
        $this->assertTrue($schema->hasColumn('tenants', 'uuid'));
        $this->assertTrue($schema->hasColumn('tenants', 'slug'));
        $this->assertTrue($schema->hasColumn('tenants', 'status'));
        $this->assertTrue($schema->hasColumn('tenants', 'settings'));
        $this->assertTrue($schema->hasColumn('tenants', 'deleted_at'));

        // Key columns on tenant_memberships
        $this->assertTrue($schema->hasColumn('tenant_memberships', 'tenant_uuid'));
        $this->assertTrue($schema->hasColumn('tenant_memberships', 'user_uuid'));
        $this->assertTrue($schema->hasColumn('tenant_memberships', 'role'));
        $this->assertTrue($schema->hasColumn('tenant_memberships', 'status'));

        // Rollback (drop in reverse order — child before parent)
        (new CreateTenantMembershipsTable())->down($schema);
        (new CreateTenantsTable())->down($schema);

        $this->assertFalse($schema->hasTable('tenant_memberships'), 'tenant_memberships should be dropped');
        $this->assertFalse($schema->hasTable('tenants'), 'tenants should be dropped');
    }

    public function test_descriptions(): void
    {
        $this->assertSame(
            'Creates the tenants registry table.',
            (new CreateTenantsTable())->getDescription()
        );
        $this->assertSame(
            'Creates the tenant_memberships bridge table.',
            (new CreateTenantMembershipsTable())->getDescription()
        );
    }
}
