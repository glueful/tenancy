<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Tests\Integration;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Tenancy\Authorization\TenantAccess;
use Glueful\Extensions\Tenancy\Bypass\Tenancy;
use Glueful\Extensions\Tenancy\Context\CurrentContext;
use Glueful\Extensions\Tenancy\Context\TenantContext;
use Glueful\Extensions\Tenancy\Exceptions\TenantAccessDeniedException;
use Glueful\Extensions\Tenancy\Models\Tenant;
use Glueful\Extensions\Tenancy\Tests\Support\TenancyTestCase;

/**
 * The Tenancy bypass facade (runAsTenant / runAsSystem / forAnyTenant) operates on the
 * CURRENT request context (via CurrentContext) and MUST save→set→try/finally→restore both
 * the active tenant and the bypass mode so nesting and exceptions never leak state.
 */
final class TenancyBypassTest extends TenancyTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        CurrentContext::set($this->appContext());
    }

    protected function tearDown(): void
    {
        CurrentContext::clear();
        parent::tearDown();
    }

    private function tc(): TenantContext
    {
        return new TenantContext($this->appContext());
    }

    public function test_run_as_tenant_scopes_and_restores_even_on_throw(): void
    {
        $a = $this->makeActiveTenant('acme');
        $tc = $this->tc();

        // Outside: no tenant, no bypass.
        $this->assertNull($tc->currentTenant());
        $this->assertNull($tc->bypassMode());

        $inside = null;
        Tenancy::runAsTenant($a, function () use ($tc, &$inside) {
            $inside = [$tc->currentTenant()?->uuid, $tc->bypassMode()];
        });

        $this->assertSame([$a->uuid, null], $inside);
        // Restored.
        $this->assertNull($tc->currentTenant());
        $this->assertNull($tc->bypassMode());

        // Now with a throwing closure: state must still be restored.
        try {
            Tenancy::runAsTenant($a, function (): void {
                throw new \RuntimeException('boom');
            });
            $this->fail('expected exception');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertNull($tc->currentTenant());
        $this->assertNull($tc->bypassMode());
    }

    public function test_run_as_system_suspends_and_restores(): void
    {
        $tc = $this->tc();

        $inside = null;
        Tenancy::runAsSystem(function () use ($tc, &$inside) {
            $inside = [$tc->currentTenant(), $tc->bypassMode()];
        });

        $this->assertSame([null, 'system'], $inside);
        $this->assertNull($tc->currentTenant());
        $this->assertNull($tc->bypassMode());
    }

    public function test_for_any_tenant_denied_without_permission_does_not_run_closure(): void
    {
        // A current user is present (request path) but the TenantAccess denies bypass.
        $this->appContext()->setRequestState('tenancy.user_uuid', 'user-123');

        $denying = new class extends TenantAccess {
            public function canBypass(ApplicationContext $context, ?string $userUuid): bool
            {
                return false;
            }
        };

        $ran = false;
        $this->expectException(TenantAccessDeniedException::class);
        try {
            Tenancy::forAnyTenant(function () use (&$ran) {
                $ran = true;
            }, true, $denying);
        } finally {
            $this->assertFalse($ran, 'closure must not run when bypass is denied');
            $this->assertNull($this->tc()->bypassMode());
        }
    }

    public function test_for_any_tenant_allowed_with_permission_runs_and_restores(): void
    {
        $this->appContext()->setRequestState('tenancy.user_uuid', 'user-123');

        $granting = new class extends TenantAccess {
            public function canBypass(ApplicationContext $context, ?string $userUuid): bool
            {
                return true;
            }
        };

        $tc = $this->tc();
        $inside = null;
        Tenancy::forAnyTenant(function () use ($tc, &$inside) {
            $inside = $tc->bypassMode();
        }, true, $granting);

        $this->assertSame('forAnyTenant', $inside);
        $this->assertNull($tc->bypassMode());
    }

    public function test_nested_calls_unwind_and_restore_original_empty_state_on_throw(): void
    {
        $a = $this->makeActiveTenant('acme');
        $tc = $this->tc();

        $this->assertNull($tc->currentTenant());
        $this->assertNull($tc->bypassMode());

        try {
            Tenancy::runAsTenant($a, function () use ($a, $tc): void {
                // Inner frame: tenant A scoped.
                $this->assertSame($a->uuid, $tc->currentTenant()?->uuid);
                Tenancy::runAsSystem(function (): void {
                    throw new \RuntimeException('inner boom');
                });
            });
            $this->fail('expected exception');
        } catch (\RuntimeException $e) {
            $this->assertSame('inner boom', $e->getMessage());
        }

        // Everything unwound: original empty state restored (no leaked bypass/tenant).
        $this->assertNull($tc->currentTenant());
        $this->assertNull($tc->bypassMode());
    }

    public function test_no_current_context_throws_runtime_exception(): void
    {
        CurrentContext::clear();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/No current ApplicationContext/');

        Tenancy::runAsSystem(static fn () => null);
    }

    public function test_run_as_tenant_resolves_string_slug(): void
    {
        $this->makeActiveTenant('globex');
        $tc = $this->tc();

        $seen = null;
        Tenancy::runAsTenant('globex', function () use ($tc, &$seen) {
            $seen = $tc->currentTenant()?->slug;
        });

        $this->assertSame('globex', $seen);
        $this->assertNull($tc->currentTenant());
    }
}
