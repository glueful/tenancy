<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Tests\Unit;

use Glueful\Container\Definition\DefinitionInterface;
use Glueful\Container\Loader\DefaultServicesLoader;
use Glueful\Extensions\Tenancy\Http\TenantMiddleware;
use Glueful\Extensions\Tenancy\TenancyServiceProvider;
use PHPUnit\Framework\TestCase;

final class ServiceProviderTest extends TestCase
{
    /**
     * Discovery-path guard. Loads the provider through the framework's real extension-discovery
     * dispatch (ContainerFactory::loadExtensionDefinitions): this provider uses the DSL
     * `services()` map, which DefaultServicesLoader compiles — and REJECTS any non-array spec.
     * If a typed Definition object is ever placed back into `services()` (e.g. the resolver-chain
     * factory, which must stay a named DSL factory), the loader throws "Service '<id>' must be an
     * array" and this test fails loudly. The other tests inspect the raw services() array and
     * would not catch that.
     */
    public function test_loads_through_extension_discovery_dispatch(): void
    {
        $provider = TenancyServiceProvider::class;

        if (method_exists($provider, 'defs')) {
            $defs = (array) $provider::defs();
        } else {
            $defs = (new DefaultServicesLoader())->load($provider::services(), $provider, false);
        }

        $this->assertNotEmpty($defs);
        // The DSL compiled the whole map, including the 'tenant' alias and the resolver-chain
        // factory (the entry that previously broke discovery as a raw FactoryDefinition object).
        $this->assertArrayHasKey(TenantMiddleware::class, $defs);
        $this->assertArrayHasKey('tenant', $defs);
        foreach ($defs as $id => $def) {
            $this->assertInstanceOf(
                DefinitionInterface::class,
                $def,
                "Definition for '{$id}' must be a DefinitionInterface after discovery-path loading"
            );
        }
    }
    public function test_services_register_tenant_as_a_container_alias_of_TenantMiddleware(): void
    {
        $services = TenancyServiceProvider::services();
        $def = $services[TenantMiddleware::class] ?? null;
        $this->assertNotNull($def, 'TenantMiddleware must be defined in services()');
        $this->assertContains('tenant', (array) ($def['alias'] ?? []));
    }
}
