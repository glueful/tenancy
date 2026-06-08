<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Tests\Unit;

use Glueful\Extensions\Tenancy\Http\TenantMiddleware;
use Glueful\Extensions\Tenancy\TenancyServiceProvider;
use PHPUnit\Framework\TestCase;

final class ServiceProviderTest extends TestCase
{
    public function test_services_register_tenant_as_a_container_alias_of_TenantMiddleware(): void
    {
        $services = TenancyServiceProvider::services();
        $def = $services[TenantMiddleware::class] ?? null;
        $this->assertNotNull($def, 'TenantMiddleware must be defined in services()');
        $this->assertContains('tenant', (array) ($def['alias'] ?? []));
    }
}
