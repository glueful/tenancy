<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Tests\Integration;

use Glueful\Database\Connection;
use Glueful\Extensions\Tenancy\Context\CurrentContext;
use Glueful\Extensions\Tenancy\Context\TenantContext;
use Glueful\Extensions\Tenancy\Query\TenantQuery;
use Glueful\Extensions\Tenancy\Query\TenantTableRegistry;
use Glueful\Extensions\Tenancy\TenancyServiceProvider;
use Glueful\Extensions\Tenancy\Tests\Support\TenancyTestCase;
use Glueful\Helpers\Utils;

/**
 * Task 5.2 — primary-table auto-injection via the Connection table hook.
 *
 * The hook receives only ($qb, $table, $conn) — no ApplicationContext — so it reaches
 * the current tenant through the request-scoped {@see CurrentContext} holder, set here
 * with CurrentContext::set($ctx). The tenant itself stays on the context's requestState
 * (set via TenantContext).
 *
 * Tests issue raw queries through the HARNESS connection ($this->connection()->table(...)).
 * The harness container maps BOTH 'database' and Connection::class to the same single
 * connection, so db($ctx) (used inside TenantQuery::tenantTable) resolves that exact same
 * connection — there is only one DB in play. The hook is process-global (static), so it
 * fires identically whether reached via $this->connection() or db($ctx).
 */
final class AutoInjectionTest extends TenancyTestCase
{
    private \Glueful\Extensions\Tenancy\Models\Tenant $tenantA;
    private \Glueful\Extensions\Tenancy\Models\Tenant $tenantB;

    protected function setUp(): void
    {
        parent::setUp();

        // Wire the auto-injection table hook (same closure boot() registers).
        TenancyServiceProvider::registerTableHook();

        // Tenant-owned fixture table.
        $this->connection()->getSchemaBuilder()->createTable('invoices', function ($table) {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('uuid', 12);
            $table->string('tenant_uuid', 12);
            $table->integer('amount');

            $table->index('tenant_uuid');
        });

        // An unregistered table to prove it is left untouched.
        $this->connection()->getSchemaBuilder()->createTable('widgets', function ($table) {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('uuid', 12);
            $table->string('tenant_uuid', 12);
            $table->string('name', 255);

            $table->index('tenant_uuid');
        });

        // Carries the same tenant_uuid column to exercise joined reads where an
        // unqualified tenant predicate would be ambiguous.
        $this->connection()->getSchemaBuilder()->createTable('customers', function ($table) {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('tenant_uuid', 12);
            $table->string('name', 255);

            $table->index('tenant_uuid');
        });

        $this->tenantA = $this->makeActiveTenant('alpha');
        $this->tenantB = $this->makeActiveTenant('beta');

        $this->seedInvoice($this->tenantA->uuid, 100);
        $this->seedInvoice($this->tenantA->uuid, 200);
        $this->seedInvoice($this->tenantB->uuid, 999);

        // Two widgets across tenants (unregistered table).
        $this->connection()->table('widgets')->insert([
            'uuid' => Utils::generateNanoID(12),
            'tenant_uuid' => $this->tenantA->uuid,
            'name' => 'wa',
        ]);
        $this->connection()->table('widgets')->insert([
            'uuid' => Utils::generateNanoID(12),
            'tenant_uuid' => $this->tenantB->uuid,
            'name' => 'wb',
        ]);

        $this->connection()->table('customers')->insert([
            'tenant_uuid' => $this->tenantA->uuid,
            'name' => 'alice',
        ]);
        $this->connection()->table('customers')->insert([
            'tenant_uuid' => $this->tenantB->uuid,
            'name' => 'bob',
        ]);
    }

    protected function tearDown(): void
    {
        Connection::clearTableHooks();
        CurrentContext::clear();
        TenantTableRegistry::clear();
        parent::tearDown();
    }

    private function seedInvoice(string $tenantUuid, int $amount): void
    {
        $this->connection()->table('invoices')->insert([
            'uuid' => Utils::generateNanoID(12),
            'tenant_uuid' => $tenantUuid,
            'amount' => $amount,
        ]);
    }

    private function activate(\Glueful\Extensions\Tenancy\Models\Tenant $tenant): void
    {
        $ctx = $this->appContext();
        CurrentContext::set($ctx);
        (new TenantContext($ctx))->setTenant($tenant);
    }

    public function testRegisteredTableIsAutoScopedToCurrentTenant(): void
    {
        TenantTableRegistry::register('invoices');
        $this->activate($this->tenantA);

        $rows = $this->connection()->table('invoices')->get();

        self::assertCount(2, $rows);
        foreach ($rows as $row) {
            self::assertSame($this->tenantA->uuid, $row['tenant_uuid']);
        }
    }

    public function testAliasedRegisteredTableIsAutoScoped(): void
    {
        // "invoices as inv" must resolve to the owned `invoices` table and inject the predicate
        // qualified by the ALIAS (`inv.tenant_uuid`), not the bare table name — else the read is
        // either left unscoped (guard fail-closes) or emits an invalid `invoices.tenant_uuid`
        // reference against an aliased FROM clause.
        TenantTableRegistry::register('invoices');
        $this->activate($this->tenantA);

        $rows = $this->connection()->table('invoices as inv')->get();

        self::assertCount(2, $rows);
        foreach ($rows as $row) {
            self::assertSame($this->tenantA->uuid, $row['tenant_uuid']);
        }
    }

    public function testAliasedJoinKeepsPrimaryTableScoped(): void
    {
        // Aliased primary owned table joined to another tenant_uuid-carrying table: the alias-qualified
        // predicate (`inv.tenant_uuid`) keeps the primary scoped and the join unambiguous.
        TenantTableRegistry::register('invoices');
        $this->activate($this->tenantA);

        $rows = $this->connection()->table('invoices as inv')
            ->join('customers as c', 'inv.tenant_uuid', '=', 'c.tenant_uuid')
            ->select(['inv.uuid', 'c.name'])
            ->get();

        self::assertCount(2, $rows);
        self::assertSame(['alice'], array_values(array_unique(array_column($rows, 'name'))));
    }

    public function testUnregisteredTableIsNotScoped(): void
    {
        // invoices NOT registered here; query widgets which is never registered.
        $this->activate($this->tenantA);

        $rows = $this->connection()->table('widgets')->get();

        self::assertCount(2, $rows);
    }

    public function testBypassReturnsAllRows(): void
    {
        TenantTableRegistry::register('invoices');

        $ctx = $this->appContext();
        CurrentContext::set($ctx);
        (new TenantContext($ctx))->setBypass('forAnyTenant');

        $rows = $this->connection()->table('invoices')->get();

        self::assertCount(3, $rows);
    }

    public function testConfigOnlyRegistrationStillAutoScopes(): void
    {
        // Register 'invoices' purely via config — no model involved.
        $ctx = $this->appContext();
        $ctx->mergeConfigDefaults('tenancy', ['tables' => ['invoices']]);
        TenantTableRegistry::loadFromConfig($ctx);

        $this->activate($this->tenantA);

        $rows = $this->connection()->table('invoices')->get();

        self::assertCount(2, $rows);
        foreach ($rows as $row) {
            self::assertSame($this->tenantA->uuid, $row['tenant_uuid']);
        }
    }

    public function testTenantQueryTenantTableScopesAndGuards(): void
    {
        TenantTableRegistry::register('invoices');
        $this->activate($this->tenantA);

        $rows = TenantQuery::tenantTable($this->appContext(), 'invoices')->get();
        self::assertCount(2, $rows);

        $this->expectException(\InvalidArgumentException::class);
        TenantQuery::tenantTable($this->appContext(), 'unregistered');
    }

    public function testJoinedReadQualifiesInjectedTenantPredicate(): void
    {
        TenantTableRegistry::register('invoices');
        $this->activate($this->tenantA);

        $rows = $this->connection()->table('invoices')
            ->join('customers', 'invoices.tenant_uuid', '=', 'customers.tenant_uuid')
            ->select(['invoices.uuid', 'customers.name'])
            ->get();

        self::assertCount(2, $rows);
        self::assertSame(['alice'], array_values(array_unique(array_column($rows, 'name'))));
    }

    public function testSameTenantBulkUpdateStillWorksWithInjectedPredicate(): void
    {
        TenantTableRegistry::register('invoices');
        $this->activate($this->tenantA);

        $affected = $this->connection()->table('invoices')->update(['amount' => 321]);

        self::assertSame(2, $affected);
        $rows = $this->connection()->query()
            ->from('invoices')
            ->where('tenant_uuid', $this->tenantA->uuid)
            ->get();
        self::assertSame([321, 321], array_column($rows, 'amount'));
    }
}
