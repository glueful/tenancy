<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Tests\Unit\Strategy;

use Glueful\Extensions\Tenancy\Strategy\RowLevelStrategy;
use Glueful\Extensions\Tenancy\Strategy\TenancyStrategyInterface;
use Glueful\Extensions\Tenancy\TenancyServiceProvider;
use PHPUnit\Framework\TestCase;

final class RowLevelStrategyTest extends TestCase
{
    public function test_row_level_strategy_reports_name_and_column_scoping(): void
    {
        $strategy = new RowLevelStrategy();

        $this->assertSame('row-level', $strategy->name());
        $this->assertTrue($strategy->scopesViaColumn());
    }

    public function test_services_bind_TenancyStrategyInterface_to_RowLevelStrategy(): void
    {
        $services = TenancyServiceProvider::services();
        $def = $services[RowLevelStrategy::class] ?? null;

        $this->assertNotNull($def, 'RowLevelStrategy must be defined in services()');
        $this->assertContains(TenancyStrategyInterface::class, (array) ($def['alias'] ?? []));
    }
}
