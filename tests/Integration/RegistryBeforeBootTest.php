<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Tests\Integration;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Tenancy\Query\TenantTableRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Task 5.1 — the registry is config-authoritative.
 *
 * Proves that {@see TenantTableRegistry::loadFromConfig()} populates the registry from
 * the `tenancy.tables` config list WITHOUT ever touching any model class. This is what
 * lets raw-query protection be in force before any BelongsToTenant model has booted.
 */
final class RegistryBeforeBootTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        TenantTableRegistry::clear();
    }

    protected function tearDown(): void
    {
        TenantTableRegistry::clear();
        parent::tearDown();
    }

    public function testLoadFromConfigRegistersTablesWithoutAnyModel(): void
    {
        $context = new ApplicationContext(basePath: sys_get_temp_dir(), environment: 'testing');
        $context->mergeConfigDefaults('tenancy', ['tables' => ['widgets']]);

        self::assertFalse(TenantTableRegistry::isTenantOwned('widgets'));

        TenantTableRegistry::loadFromConfig($context);

        // Registered purely from config — no Widget model class exists or is referenced.
        self::assertTrue(TenantTableRegistry::isTenantOwned('widgets'));
    }
}
