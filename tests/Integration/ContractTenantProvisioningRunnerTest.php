<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Tests\Integration;

use Glueful\Extensions\Contracts\Tenancy\TenantProvisioningRunner;
use Glueful\Extensions\Tenancy\Bridge\ContractTenantProvisioningRunner;
use Glueful\Extensions\Tenancy\Context\CurrentContext;
use Glueful\Extensions\Tenancy\Exceptions\TenantNotFoundException;
use Glueful\Extensions\Tenancy\Models\Tenant;
use Glueful\Extensions\Tenancy\Tests\Support\TenancyTestCase;

final class ContractTenantProvisioningRunnerTest extends TenancyTestCase
{
    protected function tearDown(): void
    {
        CurrentContext::clear();
        parent::tearDown();
    }

    public function testScopesProvisioningTenantWithoutWeakeningActiveRunner(): void
    {
        $tenant = Tenant::create($this->appContext(), [
            'uuid' => 'provision001',
            'slug' => 'provisioning',
            'name' => 'Provisioning',
        ]);
        $this->connection()->table('tenants')->where('uuid', $tenant->uuid)->update([
            'status' => 'provisioning',
        ]);
        $runner = new ContractTenantProvisioningRunner($this->appContext());

        self::assertInstanceOf(TenantProvisioningRunner::class, $runner);
        self::assertSame('provision001', $runner->runAsProvisioningTenant(
            'provision001',
            static fn (): ?string => CurrentContext::get()
                ?->getRequestState('tenancy.tenant')?->uuid,
        ));
    }

    public function testRejectsSuspendedTenant(): void
    {
        $tenant = Tenant::create($this->appContext(), [
            'uuid' => 'suspended001',
            'slug' => 'suspended',
            'name' => 'Suspended',
        ]);
        $this->connection()->table('tenants')->where('uuid', $tenant->uuid)->update([
            'status' => 'suspended',
        ]);

        $this->expectException(TenantNotFoundException::class);
        (new ContractTenantProvisioningRunner($this->appContext()))
            ->runAsProvisioningTenant('suspended001', static fn (): null => null);
    }
}
