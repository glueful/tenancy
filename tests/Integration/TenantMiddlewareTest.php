<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Tests\Integration;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Tenancy\Authorization\TenantAccess;
use Glueful\Extensions\Tenancy\Http\TenantMiddleware;
use Glueful\Extensions\Tenancy\Resolution\ResolverChain;
use Glueful\Extensions\Tenancy\Resolution\TenantResolutionPipeline;
use Glueful\Extensions\Tenancy\Resolution\TenantResolverInterface;
use Glueful\Extensions\Tenancy\Models\Tenant;
use Glueful\Extensions\Tenancy\Tests\Support\TenancyTestCase;
use Glueful\Helpers\Utils;
use Glueful\Http\Response;
use Symfony\Component\HttpFoundation\Request;

/**
 * The `tenant` middleware is the request-time entry point: it runs the validated
 * resolution pipeline, translates the pipeline's fail-closed exceptions into JSON
 * 404/403 responses, and — win or lose — always clears the request-scoped tenancy
 * state afterwards (the `finally`). The existence-hiding flag collapses 403→404.
 */
final class TenantMiddlewareTest extends TenancyTestCase
{
    /**
     * A pipeline whose chain always returns a fixed candidate, with bypass forced off,
     * so the middleware exercises the real validation path against seeded data.
     */
    private function pipelineFor(?string $candidate): TenantResolutionPipeline
    {
        $resolver = new class ($candidate) implements TenantResolverInterface {
            public function __construct(private ?string $candidate)
            {
            }

            public function resolve(Request $request, ApplicationContext $context): ?string
            {
                return $this->candidate;
            }
        };

        return new TenantResolutionPipeline(new ResolverChain([$resolver]), new TenantAccess());
    }

    private function requestForUser(?string $userUuid): Request
    {
        $request = Request::create('/');
        if ($userUuid !== null) {
            $request->attributes->set('auth.user.uuid', $userUuid);
        }

        return $request;
    }

    public function test_valid_tenant_member_runs_next_and_clears_context(): void
    {
        $ctx = $this->appContext();
        $tenant = $this->makeActiveTenant('acme');
        $userUuid = Utils::generateNanoID(12);
        $this->makeMembership($tenant->uuid, $userUuid, 'member', 'active');

        $middleware = new TenantMiddleware($this->pipelineFor('acme'), $ctx);
        $ok = Response::success(['done' => true]);

        $ran = false;
        $result = $middleware->handle(
            $this->requestForUser($userUuid),
            function (Request $r) use (&$ran, $ok): Response {
                $ran = true;
                return $ok;
            }
        );

        $this->assertTrue($ran, '$next should have run for a valid member');
        $this->assertSame($ok, $result);

        // The finally must have cleared the tenancy request-state keys.
        $this->assertNull($ctx->getRequestState('tenancy.tenant'));
        $this->assertNull($ctx->getRequestState('tenancy.bypass'));
    }

    public function test_inactive_tenant_returns_404_and_does_not_run_next(): void
    {
        $ctx = $this->appContext();
        $tenant = $this->makeActiveTenant('suspended-co', 'Suspended Co');
        $this->connection()->table('tenants')
            ->where('uuid', $tenant->uuid)
            ->update(['status' => 'suspended']);

        $middleware = new TenantMiddleware($this->pipelineFor('suspended-co'), $ctx);

        $ran = false;
        $result = $middleware->handle(
            $this->requestForUser(Utils::generateNanoID(12)),
            function (Request $r) use (&$ran): Response {
                $ran = true;
                return Response::success();
            }
        );

        $this->assertFalse($ran, '$next must not run when the tenant is unresolvable');
        $this->assertSame(404, $result->getStatusCode());
        $this->assertNull($ctx->getRequestState('tenancy.tenant'));
    }

    public function test_authenticated_non_member_returns_403(): void
    {
        $ctx = $this->appContext();
        $this->makeActiveTenant('acme');

        $middleware = new TenantMiddleware($this->pipelineFor('acme'), $ctx);

        $result = $middleware->handle(
            $this->requestForUser(Utils::generateNanoID(12)),
            fn(Request $r): Response => Response::success()
        );

        $this->assertSame(403, $result->getStatusCode());
        $this->assertNull($ctx->getRequestState('tenancy.tenant'));
    }

    public function test_unauthenticated_tenant_candidate_fails_closed_by_default(): void
    {
        $ctx = $this->appContext();
        $this->makeActiveTenant('acme');

        $middleware = new TenantMiddleware($this->pipelineFor('acme'), $ctx);

        $ran = false;
        $result = $middleware->handle(
            $this->requestForUser(null),
            function (Request $r) use (&$ran): Response {
                $ran = true;
                return Response::success();
            }
        );

        self::assertFalse($ran, '$next must not run for unauthenticated tenant selection');
        self::assertSame(403, $result->getStatusCode());
        self::assertNull($ctx->getRequestState('tenancy.tenant'));
    }

    public function test_hide_existence_collapses_403_to_404(): void
    {
        $ctx = $this->appContext();
        $ctx->mergeConfigDefaults('tenancy', ['enforcement' => ['hide_existence' => true]]);
        $this->makeActiveTenant('acme');

        $middleware = new TenantMiddleware($this->pipelineFor('acme'), $ctx);

        $result = $middleware->handle(
            $this->requestForUser(Utils::generateNanoID(12)),
            fn(Request $r): Response => Response::success()
        );

        $this->assertSame(404, $result->getStatusCode(), 'hide_existence must collapse 403 to 404');
    }
}
