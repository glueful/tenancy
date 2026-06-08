<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Tests\Integration;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Helpers\Utils;
use Glueful\Migrations\CreateTenantMembershipsTable;
use Glueful\Migrations\CreateTenantsTable;
use Glueful\Extensions\Tenancy\Models\Tenant;
use Glueful\Extensions\Tenancy\Models\TenantMembership;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * DB-backed tests for the central (non-tenant-scoped) registry models.
 *
 * Harness mirrors MigrationsTest: a bare in-memory SQLite Connection with migrations
 * run against its schema builder. To exercise the context-first ORM static API
 * (Model::create($ctx, ...), Model::query($ctx), etc.) we wrap the Connection in a
 * tiny PSR-11 container exposed as 'database' on an ApplicationContext — the same
 * binding key Model::getConnection() resolves.
 */
final class TenantModelTest extends TestCase
{
    private ApplicationContext $context;
    private Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = new Connection([
            'engine' => 'sqlite',
            'sqlite' => ['primary' => ':memory:'],
            'pooling' => ['enabled' => false],
        ]);

        $schema = $this->connection->getSchemaBuilder();
        (new CreateTenantsTable())->up($schema);
        (new CreateTenantMembershipsTable())->up($schema);

        $connection = $this->connection;
        $container = new class ($connection) implements ContainerInterface {
            public function __construct(private Connection $connection)
            {
            }

            public function get(string $id): mixed
            {
                if ($id === 'database') {
                    return $this->connection;
                }
                throw new \RuntimeException("Unknown service: {$id}");
            }

            public function has(string $id): bool
            {
                return $id === 'database';
            }
        };

        $this->context = new ApplicationContext(basePath: sys_get_temp_dir(), environment: 'testing');
        $this->context->setContainer($container);
    }

    public function test_tenant_create_find_by_slug_and_is_active(): void
    {
        $created = Tenant::create($this->context, [
            'uuid' => Utils::generateNanoID(12),
            'slug' => 'acme',
            'name' => 'Acme',
            'status' => 'active',
        ]);

        $this->assertTrue($created->exists);

        $found = Tenant::findBySlug($this->context, 'acme');

        $this->assertInstanceOf(Tenant::class, $found);
        $this->assertSame('acme', $found->slug);
        $this->assertSame('Acme', $found->name);
        $this->assertTrue($found->isActive());

        $this->assertNull(Tenant::findBySlug($this->context, 'does-not-exist'));
    }

    public function test_inactive_tenant_is_not_active(): void
    {
        Tenant::create($this->context, [
            'uuid' => Utils::generateNanoID(12),
            'slug' => 'dormant',
            'name' => 'Dormant',
            'status' => 'suspended',
        ]);

        $tenant = Tenant::findBySlug($this->context, 'dormant');

        $this->assertInstanceOf(Tenant::class, $tenant);
        $this->assertFalse($tenant->isActive());
    }

    public function test_membership_for_user_returns_active_membership(): void
    {
        $tenant = Tenant::create($this->context, [
            'uuid' => Utils::generateNanoID(12),
            'slug' => 'globex',
            'name' => 'Globex',
            'status' => 'active',
        ]);

        $userUuid = Utils::generateNanoID(12);

        TenantMembership::create($this->context, [
            'uuid' => Utils::generateNanoID(12),
            'tenant_uuid' => $tenant->uuid,
            'user_uuid' => $userUuid,
            'role' => 'admin',
            'status' => 'active',
        ]);

        // An inactive membership in another tenant for the same user must be filtered out.
        $otherTenant = Tenant::create($this->context, [
            'uuid' => Utils::generateNanoID(12),
            'slug' => 'initech',
            'name' => 'Initech',
            'status' => 'active',
        ]);
        TenantMembership::create($this->context, [
            'uuid' => Utils::generateNanoID(12),
            'tenant_uuid' => $otherTenant->uuid,
            'user_uuid' => $userUuid,
            'role' => 'member',
            'status' => 'inactive',
        ]);

        $memberships = TenantMembership::forUser($this->context, $userUuid)
            ->active()
            ->get();

        $this->assertCount(1, $memberships);
        $membership = $memberships->first();
        $this->assertSame($tenant->uuid, $membership->tenant_uuid);
        $this->assertSame('admin', $membership->role);
        $this->assertSame('active', $membership->status);
    }

    public function test_tenant_is_central_no_tenant_scoping(): void
    {
        // Three tenants with distinct uuids — there is NO tenant_uuid predicate injected
        // for the central registry, so all rows come back regardless of any tenant context.
        foreach (['one', 'two', 'three'] as $slug) {
            Tenant::create($this->context, [
                'uuid' => Utils::generateNanoID(12),
                'slug' => $slug,
                'name' => ucfirst($slug),
                'status' => 'active',
            ]);
        }

        $all = Tenant::all($this->context);

        $this->assertCount(3, $all);
    }
}
