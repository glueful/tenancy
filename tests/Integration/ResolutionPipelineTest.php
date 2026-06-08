<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Tests\Integration;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Tenancy\Authorization\TenantAccess;
use Glueful\Extensions\Tenancy\Exceptions\TenantAccessDeniedException;
use Glueful\Extensions\Tenancy\Exceptions\TenantNotFoundException;
use Glueful\Extensions\Tenancy\Models\Tenant;
use Glueful\Extensions\Tenancy\Resolution\ResolverChain;
use Glueful\Extensions\Tenancy\Resolution\TenantResolutionPipeline;
use Glueful\Extensions\Tenancy\Resolution\TenantResolverInterface;
use Glueful\Extensions\Tenancy\Tests\Support\TenancyTestCase;
use Glueful\Helpers\Utils;
use Symfony\Component\HttpFoundation\Request;

/**
 * The validated tenant-resolution pipeline is the security boundary: it resolves a
 * candidate, then refuses to trust it unless the tenant exists, is active, and the
 * authenticated principal is a member (or holds a bypass). It fails CLOSED — unknown and
 * inactive tenants are indistinguishable 404s; a real-tenant non-member is a 403.
 */
final class ResolutionPipelineTest extends TenancyTestCase
{
    /**
     * A ResolverChain that ignores its resolvers and always returns a fixed candidate
     * (or null), so the pipeline's validation branches can be exercised in isolation.
     */
    private function chainReturning(?string $candidate): ResolverChain
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

        return new ResolverChain([$resolver]);
    }

    /**
     * A TenantAccess stub that grants (or denies) bypass regardless of input.
     */
    private function accessGranting(bool $bypass): TenantAccess
    {
        return new class ($bypass) extends TenantAccess {
            public function __construct(private bool $bypass)
            {
            }

            public function canBypass(ApplicationContext $context, ?string $userUuid): bool
            {
                return $this->bypass;
            }
        };
    }

    private function requestForUser(?string $userUuid): Request
    {
        $request = Request::create('/');
        if ($userUuid !== null) {
            $request->attributes->set('auth.user.uuid', $userUuid);
        }

        return $request;
    }

    public function test_active_tenant_with_authenticated_member_passes(): void
    {
        $ctx = $this->appContext();
        $tenant = $this->makeActiveTenant('acme');
        $userUuid = Utils::generateNanoID(12);
        $this->makeMembership($tenant->uuid, $userUuid, 'member', 'active');

        // resolve by slug
        $pipeline = new TenantResolutionPipeline($this->chainReturning('acme'), $this->accessGranting(false));
        $pipeline->resolve($this->requestForUser($userUuid), $ctx, true);

        $this->assertSame($tenant->uuid, $ctx->getRequestState('tenancy.tenant')->uuid);
        $this->assertNull($ctx->getRequestState('tenancy.bypass'));
    }

    public function test_resolves_by_uuid_as_well_as_slug(): void
    {
        $ctx = $this->appContext();
        $tenant = $this->makeActiveTenant('beta');
        $userUuid = Utils::generateNanoID(12);
        $this->makeMembership($tenant->uuid, $userUuid, 'member', 'active');

        // resolve by uuid this time
        $pipeline = new TenantResolutionPipeline($this->chainReturning($tenant->uuid), $this->accessGranting(false));
        $pipeline->resolve($this->requestForUser($userUuid), $ctx, true);

        $this->assertSame($tenant->uuid, $ctx->getRequestState('tenancy.tenant')->uuid);
    }

    public function test_unknown_candidate_throws_not_found(): void
    {
        $ctx = $this->appContext();
        $pipeline = new TenantResolutionPipeline($this->chainReturning('does-not-exist'), $this->accessGranting(false));

        $this->expectException(TenantNotFoundException::class);
        $pipeline->resolve($this->requestForUser(Utils::generateNanoID(12)), $ctx, true);
    }

    public function test_inactive_tenant_throws_not_found(): void
    {
        $ctx = $this->appContext();
        Tenant::create($ctx, [
            'uuid' => Utils::generateNanoID(12),
            'slug' => 'suspended-co',
            'name' => 'Suspended Co',
            'status' => 'suspended',
        ]);
        $pipeline = new TenantResolutionPipeline($this->chainReturning('suspended-co'), $this->accessGranting(false));

        $this->expectException(TenantNotFoundException::class);
        $pipeline->resolve($this->requestForUser(Utils::generateNanoID(12)), $ctx, true);
    }

    public function test_authenticated_non_member_without_bypass_throws_access_denied(): void
    {
        $ctx = $this->appContext();
        $this->makeActiveTenant('acme');
        $pipeline = new TenantResolutionPipeline($this->chainReturning('acme'), $this->accessGranting(false));

        $this->expectException(TenantAccessDeniedException::class);
        $pipeline->resolve($this->requestForUser(Utils::generateNanoID(12)), $ctx, true);
    }

    public function test_authenticated_user_with_bypass_passes_without_membership(): void
    {
        $ctx = $this->appContext();
        $tenant = $this->makeActiveTenant('acme');
        // no membership row created
        $pipeline = new TenantResolutionPipeline($this->chainReturning('acme'), $this->accessGranting(true));

        $pipeline->resolve($this->requestForUser(Utils::generateNanoID(12)), $ctx, true);

        $this->assertSame($tenant->uuid, $ctx->getRequestState('tenancy.tenant')->uuid);
        $this->assertSame('forAnyTenant', $ctx->getRequestState('tenancy.bypass'));
    }

    public function test_required_route_with_null_candidate_throws_not_found(): void
    {
        $ctx = $this->appContext();
        $pipeline = new TenantResolutionPipeline($this->chainReturning(null), $this->accessGranting(false));

        $this->expectException(TenantNotFoundException::class);
        $pipeline->resolve($this->requestForUser(Utils::generateNanoID(12)), $ctx, true);
    }

    public function test_optional_route_with_null_candidate_leaves_context_empty(): void
    {
        $ctx = $this->appContext();
        $pipeline = new TenantResolutionPipeline($this->chainReturning(null), $this->accessGranting(false));

        $pipeline->resolve($this->requestForUser(Utils::generateNanoID(12)), $ctx, false);

        $this->assertNull($ctx->getRequestState('tenancy.tenant'));
        $this->assertNull($ctx->getRequestState('tenancy.bypass'));
    }

    public function test_unauthenticated_request_with_active_tenant_passes_without_membership(): void
    {
        $ctx = $this->appContext();
        $tenant = $this->makeActiveTenant('acme');
        // no auth.user.uuid attribute, no membership row
        $pipeline = new TenantResolutionPipeline($this->chainReturning('acme'), $this->accessGranting(false));

        $pipeline->resolve($this->requestForUser(null), $ctx, true);

        $this->assertSame($tenant->uuid, $ctx->getRequestState('tenancy.tenant')->uuid);
        $this->assertNull($ctx->getRequestState('tenancy.bypass'));
    }
}
