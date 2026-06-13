<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Tests\Integration;

use Glueful\Extensions\Tenancy\TenancyServiceProvider;
use Glueful\Extensions\Tenancy\Context\CurrentContext;
use Glueful\Extensions\Tenancy\Context\TenantContext;
use Glueful\Extensions\Tenancy\Query\TenantTableRegistry;
use Glueful\Extensions\Tenancy\Tests\Support\TenancyTestCase;

final class ServiceProviderBootTest extends TenancyTestCase
{
    protected function tearDown(): void
    {
        \Glueful\Database\Connection::clearTableHooks();
        CurrentContext::clear();
        TenantTableRegistry::clear();
        parent::tearDown();
    }

    public function test_enforcement_registration_failures_rethrow_outside_production(): void
    {
        $ctx = $this->appContext();
        $ctx->mergeConfigDefaults('tenancy', [
            'tables' => ['invoices', 123],
        ]);

        $provider = new TenancyServiceProvider($ctx->getContainer());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('tenancy.tables');

        $provider->boot($ctx);
    }

    public function test_disabled_tenancy_skips_enforcement_registration(): void
    {
        $ctx = $this->appContext();
        $ctx->mergeConfigDefaults('tenancy', [
            'enabled' => false,
            'tables' => ['invoices'],
        ]);

        $this->connection()->getSchemaBuilder()->createTable('invoices', function ($table) {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('tenant_uuid', 12);
            $table->integer('amount');
        });

        $tenantA = $this->makeActiveTenant('boot-a');
        $tenantB = $this->makeActiveTenant('boot-b');

        $this->connection()->table('invoices')->insert([
            'tenant_uuid' => $tenantA->uuid,
            'amount' => 100,
        ]);
        $this->connection()->table('invoices')->insert([
            'tenant_uuid' => $tenantB->uuid,
            'amount' => 200,
        ]);

        (new TenantContext($ctx))->setTenant($tenantA);
        CurrentContext::set($ctx);

        (new TenancyServiceProvider($ctx->getContainer()))->boot($ctx);

        $rows = $this->connection()->table('invoices')->get();

        self::assertCount(2, $rows);
    }
}
