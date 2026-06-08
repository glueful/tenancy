<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Tests\Integration;

use Glueful\Extensions\Tenancy\Context\TenantContext;
use Glueful\Extensions\Tenancy\Tests\Support\TenancyTestCase;

/**
 * The TenantContext is request-scoped: it stores the current tenant and bypass mode in
 * ApplicationContext::requestState (never a static/singleton).
 */
final class TenantContextTest extends TenancyTestCase
{
    public function test_set_get_clear_current_tenant_is_request_scoped(): void
    {
        $ctx = $this->appContext();
        $tc = new TenantContext($ctx);
        $this->assertFalse($tc->hasTenant());

        $tenant = $this->makeActiveTenant('acme');
        $tc->setTenant($tenant);

        $this->assertTrue($tc->hasTenant());
        $this->assertSame($tenant->uuid, $tc->currentTenantUuid());
        // stored under requestState, not a static:
        $this->assertSame($tenant->uuid, $ctx->getRequestState('tenancy.tenant')->uuid);

        $tc->clear();
        $this->assertFalse($tc->hasTenant());
        $this->assertNull($ctx->getRequestState('tenancy.tenant'));
        $this->assertNull($ctx->getRequestState('tenancy.bypass'));
    }

    public function test_clear_does_not_wipe_unrelated_request_state(): void
    {
        $ctx = $this->appContext();
        $ctx->setRequestState('something.else', 'keep-me');
        $tc = new TenantContext($ctx);
        $tc->setTenant($this->makeActiveTenant('acme'));
        $tc->clear();
        $this->assertSame('keep-me', $ctx->getRequestState('something.else')); // untouched
    }
}
