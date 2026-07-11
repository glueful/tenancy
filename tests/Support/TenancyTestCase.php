<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Tests\Support;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Tenancy\Models\Tenant;
use Glueful\Extensions\Tenancy\Models\TenantMembership;
use Glueful\Helpers\Utils;
use Glueful\Migrations\CreateTenantMembershipsTable;
use Glueful\Migrations\CreateTenantDomainsTable;
use Glueful\Migrations\CreateTenantsTable;
use Glueful\Migrations\CreateReleasedHostsTable;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Reusable in-memory-SQLite harness for tenancy DB-backed tests.
 *
 * Builds a bare {@see Connection} against an in-memory SQLite database, runs the
 * tenancy migrations (tenants + tenant_memberships) against its schema builder, and
 * wraps the connection in a tiny PSR-11 container exposed as the 'database' binding on
 * a real {@see ApplicationContext} — the same key Model::getConnection() resolves. The
 * harness is rebuilt per test (setUp) so each test is fully isolated.
 */
abstract class TenancyTestCase extends TestCase
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
        (new CreateTenantDomainsTable())->up($schema);
        (new CreateReleasedHostsTable())->up($schema);

        $connection = $this->connection;
        $container = new class ($connection) implements ContainerInterface {
            public function __construct(private Connection $connection)
            {
            }

            public function get(string $id): mixed
            {
                // 'database' is what Model::getConnection() resolves; Connection::class is
                // what the db()/app() helpers resolve — both map to the one harness
                // connection so the process-global table hook fires on the same DB.
                if ($id === 'database' || $id === Connection::class) {
                    return $this->connection;
                }
                throw new \RuntimeException("Unknown service: {$id}");
            }

            public function has(string $id): bool
            {
                return $id === 'database' || $id === Connection::class;
            }
        };

        $this->context = new ApplicationContext(basePath: sys_get_temp_dir(), environment: 'testing');
        $this->context->setContainer($container);
    }

    /**
     * The request-scoped application context whose container resolves 'database' to the
     * in-memory SQLite connection (with tenancy migrations already run).
     */
    protected function appContext(): ApplicationContext
    {
        return $this->context;
    }

    /**
     * The underlying in-memory SQLite connection backing {@see appContext()}.
     */
    protected function connection(): Connection
    {
        return $this->connection;
    }

    /**
     * Create and return an active tenant.
     */
    protected function makeActiveTenant(string $slug, ?string $name = null): Tenant
    {
        return Tenant::create($this->context, [
            'uuid' => Utils::generateNanoID(12),
            'slug' => $slug,
            'name' => $name ?? ucfirst($slug),
        ]);
    }

    /**
     * Create and return a tenant membership.
     */
    protected function makeMembership(
        string $tenantUuid,
        string $userUuid,
        string $role = 'member',
        string $status = 'active'
    ): TenantMembership {
        $membership = TenantMembership::create($this->context, [
            'uuid' => Utils::generateNanoID(12),
            'tenant_uuid' => $tenantUuid,
            'user_uuid' => $userUuid,
        ]);

        if ($role !== 'member' || $status !== 'active') {
            $this->connection()->table('tenant_memberships')
                ->where('uuid', $membership->uuid)
                ->update(['role' => $role, 'status' => $status]);

            $membership = TenantMembership::query($this->context)
                ->where('uuid', $membership->uuid)
                ->first();
        }

        return $membership;
    }
}
