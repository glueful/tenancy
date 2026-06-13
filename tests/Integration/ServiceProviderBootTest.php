<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Tests\Integration;

use Glueful\Extensions\Tenancy\TenancyServiceProvider;
use Glueful\Extensions\Tenancy\Tests\Support\TenancyTestCase;

final class ServiceProviderBootTest extends TenancyTestCase
{
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
}
