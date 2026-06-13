<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Tests\Integration;

use Glueful\Database\Execution\QueryExecutor;
use Glueful\Extensions\Tenancy\Context\CurrentContext;
use Glueful\Extensions\Tenancy\Context\TenantContext;
use Glueful\Extensions\Tenancy\Exceptions\TenantScopeViolationException;
use Glueful\Extensions\Tenancy\Query\TenantQueryGuard;
use Glueful\Extensions\Tenancy\Query\TenantTableRegistry;
use Glueful\Extensions\Tenancy\Tests\Support\TenancyTestCase;

/**
 * Task 5.3 — the {@see TenantQueryGuard} wired into the real {@see QueryExecutor}
 * pre-execution interceptor seam.
 *
 * The guard is registered via QueryExecutor::addQueryInterceptor() (cleared in tearDown).
 * It runs inside executeStatement() BEFORE prepare/execute, so an unscoped raw query that
 * reaches the executor referencing a registered tenant-owned table throws.
 *
 * Producing a genuinely UNSCOPED execution: the primary-table auto-injection hook only
 * fires through Connection::table('invoices') (it iterates Connection::$tableHooks inside
 * table()). We deliberately bypass that path by building the query through
 * connection()->query()->from('invoices') — query() returns a bare builder and from() does
 * NOT run the table hooks, so no tenant_uuid predicate is injected. The compiled
 * "SELECT * FROM \"invoices\"" reaches executeStatement() unscoped. The harness runs as
 * 'testing' (a dev env) so the guard's dev action is 'throw'.
 */
final class TenantQueryGuardIntegrationTest extends TenancyTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->connection()->getSchemaBuilder()->createTable('invoices', function ($table) {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('uuid', 12);
            $table->string('tenant_uuid', 12);
            $table->integer('amount');
            $table->index('tenant_uuid');
        });

        TenantTableRegistry::register('invoices');
        QueryExecutor::addQueryInterceptor(new TenantQueryGuard());
    }

    protected function tearDown(): void
    {
        QueryExecutor::clearQueryInterceptors();
        CurrentContext::clear();
        TenantTableRegistry::clear();
        parent::tearDown();
    }

    private function activateTenant(): void
    {
        $ctx = $this->appContext();
        CurrentContext::set($ctx);
        $tenant = $this->makeActiveTenant('alpha');
        (new TenantContext($ctx))->setTenant($tenant);
    }

    public function testUnscopedRawQueryAgainstTenantTableThrows(): void
    {
        $this->activateTenant();

        $this->expectException(TenantScopeViolationException::class);

        // query()->from('invoices') bypasses the table hook (only table() runs hooks),
        // so the compiled SQL reaches executeStatement() unscoped.
        $this->connection()->query()
            ->from('invoices')
            ->get();
    }

    public function testProperlyScopedRawQueryDoesNotThrow(): void
    {
        $this->activateTenant();
        $uuid = (new TenantContext($this->appContext()))->currentTenantUuid();

        $rows = $this->connection()->query()
            ->from('invoices')
            ->where('tenant_uuid', $uuid)
            ->get();

        self::assertIsArray($rows);
    }

    public function test_raw_insert_cannot_write_foreign_tenant_uuid(): void
    {
        $this->activateTenant();
        $victim = $this->makeActiveTenant('beta');

        $this->expectException(TenantScopeViolationException::class);

        $this->connection()->query()
            ->from('invoices')
            ->insert([
                'uuid' => 'foreignraw01',
                'tenant_uuid' => $victim->uuid,
                'amount' => 500,
            ]);
    }

    public function test_raw_update_cannot_reassign_rows_to_foreign_tenant_uuid(): void
    {
        $this->activateTenant();
        $current = (new TenantContext($this->appContext()))->currentTenantUuid();
        $victim = $this->makeActiveTenant('beta');

        $this->connection()->query()
            ->from('invoices')
            ->insert([
                'uuid' => 'ownraw00001',
                'tenant_uuid' => $current,
                'amount' => 100,
            ]);

        $this->expectException(TenantScopeViolationException::class);

        $this->connection()->query()
            ->from('invoices')
            ->where('tenant_uuid', $current)
            ->update(['tenant_uuid' => $victim->uuid]);
    }
}
