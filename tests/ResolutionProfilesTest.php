<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Tests;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Contracts\Tenancy\FullTenantResolutionReadiness;
use Glueful\Extensions\Tenancy\Authorization\TenantAccess;
use Glueful\Extensions\Tenancy\Context\CurrentContext;
use Glueful\Extensions\Tenancy\Exceptions\TenantAccessDeniedException;
use Glueful\Extensions\Tenancy\Http\TenantMiddleware;
use Glueful\Extensions\Tenancy\Resolution\ResolutionProfile;
use Glueful\Extensions\Tenancy\Resolution\ResolverChain;
use Glueful\Extensions\Tenancy\Resolution\TenantResolutionPipeline;
use Glueful\Extensions\Tenancy\Tests\Support\TenancyTestCase;
use Glueful\Helpers\Utils;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

final class ResolutionProfilesTest extends TenancyTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->appContext()->mergeConfigDefaults('tenancy', require __DIR__ . '/../config/tenancy.php');
    }

    public function testPublicProfileAllowsAnonymousActiveTenant(): void
    {
        $tenant = $this->makeActiveTenant('acme');
        $request = Request::create('https://acme.example.com/');
        $this->appContext()->mergeConfigDefaults('tenancy', [
            'public_origin' => ['base_domain' => 'example.com'],
        ]);
        $pipeline = new TenantResolutionPipeline(new ResolverChain([]), new TenantAccess());

        $pipeline->resolve(
            $request,
            $this->appContext(),
            true,
            ResolutionProfile::fromConfig($this->appContext(), 'public')
        );

        self::assertSame($tenant->uuid, $this->appContext()->getRequestState('tenancy.tenant')->uuid);
    }

    public function testAdminProfileRejectsConflictingSelectorsAndSlugOnlyHeader(): void
    {
        $tenant = $this->makeActiveTenant('acme');
        $user = Utils::generateNanoID(12);
        $this->makeMembership($tenant->uuid, $user);
        $pipeline = new TenantResolutionPipeline(new ResolverChain([]), new TenantAccess());
        $profile = ResolutionProfile::fromConfig($this->appContext(), 'admin');

        $conflict = Request::create('/');
        $conflict->headers->set('X-Tenant-Id', $tenant->uuid);
        $conflict->attributes->set('jwt.claims', ['tenant_id' => Utils::generateNanoID(12)]);
        $conflict->attributes->set('auth.user.uuid', $user);
        try {
            $pipeline->resolve($conflict, $this->appContext(), true, $profile);
            self::fail('Conflicting selectors must be rejected.');
        } catch (TenantAccessDeniedException) {
            self::addToAssertionCount(1);
        }

        $slug = Request::create('/');
        $slug->headers->set('X-Tenant-Id', 'acme');
        $slug->attributes->set('auth.user.uuid', $user);
        $this->expectException(\Glueful\Extensions\Tenancy\Exceptions\TenantNotFoundException::class);
        $pipeline->resolve($slug, $this->appContext(), true, $profile);
    }

    public function testInactiveProfileReturnsBeforeMutatingRequestState(): void
    {
        $base = $this->appContext()->getContainer();
        $readiness = new class implements FullTenantResolutionReadiness {
            public function isReady(ApplicationContext $context): bool
            {
                return false;
            }
        };
        $this->appContext()->setContainer(new class ($base, $readiness) implements ContainerInterface {
            public function __construct(
                private ContainerInterface $base,
                private FullTenantResolutionReadiness $readiness
            ) {
            }

            public function get(string $id): mixed
            {
                return $id === FullTenantResolutionReadiness::class
                    ? $this->readiness
                    : $this->base->get($id);
            }

            public function has(string $id): bool
            {
                return $id === FullTenantResolutionReadiness::class || $this->base->has($id);
            }
        });

        $middleware = new TenantMiddleware(
            new TenantResolutionPipeline(new ResolverChain([]), new TenantAccess()),
            $this->appContext()
        );
        $request = Request::create('https://unmapped.example.com/');
        $result = $middleware->handle($request, static fn (): string => 'passed', 'public');

        self::assertSame('passed', $result);
        self::assertNull(CurrentContext::get());
        self::assertNull($this->appContext()->getRequestState('tenancy.user_uuid'));
    }
}
