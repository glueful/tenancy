<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Tests\Integration\Bridge;

use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Extensions\Contracts\Tenancy\TenantEnforcementProbe;
use Glueful\Extensions\Contracts\Tenancy\TenantTableRegistry as TenantTableRegistryContract;
use Glueful\Extensions\Tenancy\Bridge\ContractEnforcementProbe;
use Glueful\Extensions\Tenancy\Bridge\ContractTableRegistry;
use Glueful\Extensions\Tenancy\Bridge\ContractTenantResolver;
use Glueful\Extensions\Tenancy\Context\TenantContext;
use Glueful\Extensions\Tenancy\Query\TenantTableRegistry;
use Glueful\Extensions\Tenancy\TenancyServiceProvider;
use Glueful\Extensions\Tenancy\Tests\Support\TenancyTestCase;

final class ContractsBridgeTest extends TenancyTestCase
{
    protected function tearDown(): void
    {
        TenantTableRegistry::clear();
        parent::tearDown();
    }

    public function testContractResolverReturnsActiveTenantOrEmpty(): void
    {
        $resolver = new ContractTenantResolver();

        self::assertSame('', $resolver->tenantUuid($this->appContext()));

        $tenant = $this->makeActiveTenant('contracts');
        (new TenantContext($this->appContext()))->setTenant($tenant);

        self::assertSame($tenant->uuid, $resolver->tenantUuid($this->appContext()));
    }

    public function testContractRegistryFeedsTheBackstop(): void
    {
        (new ContractTableRegistry())->register(['commerce_products', 'commerce_orders']);

        self::assertTrue(TenantTableRegistry::isTenantOwned('commerce_products'));
        self::assertTrue(TenantTableRegistry::isTenantOwned('commerce_orders'));

        (new ContractTableRegistry())->register(['commerce_products']);

        self::assertTrue(TenantTableRegistry::isTenantOwned('commerce_products'));
    }

    public function testEnforcementProbeReadsTheBackstop(): void
    {
        TenantTableRegistry::register('content_entries');
        $probe = new ContractEnforcementProbe();

        self::assertTrue($probe->isRegistered('content_entries'));
        self::assertFalse($probe->isRegistered('system_flags'));
        self::assertSame(['content_entries'], $probe->registeredTables());
    }

    public function testProviderBindsContractIds(): void
    {
        $services = TenancyServiceProvider::services();

        self::assertSame(ContractTenantResolver::class, $services[CurrentTenantResolver::class]['class'] ?? null);
        self::assertSame(ContractTableRegistry::class, $services[TenantTableRegistryContract::class]['class'] ?? null);
        self::assertSame(ContractEnforcementProbe::class, $services[TenantEnforcementProbe::class]['class'] ?? null);
    }
}
