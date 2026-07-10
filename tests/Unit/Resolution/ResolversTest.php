<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Tests\Unit\Resolution;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Tenancy\Resolution\Resolvers\ActiveSessionResolver;
use Glueful\Extensions\Tenancy\Resolution\Resolvers\HeaderResolver;
use Glueful\Extensions\Tenancy\Resolution\Resolvers\JwtClaimResolver;
use Glueful\Extensions\Tenancy\Resolution\Resolvers\PathResolver;
use Glueful\Extensions\Tenancy\Resolution\Resolvers\QueryResolver;
use Glueful\Extensions\Tenancy\Resolution\Resolvers\SubdomainResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class ResolversTest extends TestCase
{
    private function context(): ApplicationContext
    {
        return new ApplicationContext(basePath: sys_get_temp_dir(), environment: 'testing');
    }

    public function test_header_resolver_extracts_and_returns_null(): void
    {
        $resolver = new HeaderResolver();
        $context = $this->context();

        $populated = new Request();
        $populated->headers->set('X-Tenant-Id', '7b3c');
        $this->assertSame('7b3c', $resolver->resolve($populated, $context));

        $this->assertNull($resolver->resolve(new Request(), $context));
    }

    public function test_query_resolver_extracts_and_returns_null(): void
    {
        $resolver = new QueryResolver();
        $context = $this->context();

        $populated = Request::create('/?tenant_id=acme');
        $this->assertSame('acme', $resolver->resolve($populated, $context));

        $this->assertNull($resolver->resolve(Request::create('/'), $context));
    }

    public function test_subdomain_resolver_extracts_and_returns_null(): void
    {
        $resolver = new SubdomainResolver();
        $context = $this->context();
        $context->mergeConfigDefaults('tenancy', ['public_origin' => ['base_domain' => 'app.com']]);

        $populated = Request::create('http://acme.app.com/');
        $this->assertSame('acme', $resolver->resolve($populated, $context));

        $bare = Request::create('http://app.com/');
        $this->assertNull($resolver->resolve($bare, $context));
    }

    public function test_path_resolver_extracts_and_returns_null(): void
    {
        $resolver = new PathResolver();
        $context = $this->context();

        $populated = Request::create('/t/acme/posts');
        $this->assertSame('acme', $resolver->resolve($populated, $context));

        $this->assertNull($resolver->resolve(Request::create('/posts'), $context));
    }

    public function test_jwt_claim_resolver_extracts_and_returns_null(): void
    {
        $resolver = new JwtClaimResolver();
        $context = $this->context();

        $populated = new Request();
        $populated->attributes->set('jwt.claims', ['tenant_id' => 'xyz']);
        $this->assertSame('xyz', $resolver->resolve($populated, $context));

        $this->assertNull($resolver->resolve(new Request(), $context));
    }

    public function test_active_session_resolver_extracts_and_returns_null(): void
    {
        $resolver = new ActiveSessionResolver();
        $context = $this->context();

        $populated = new Request();
        $populated->attributes->set('tenancy.active_tenant', 'sel');
        $this->assertSame('sel', $resolver->resolve($populated, $context));

        $this->assertNull($resolver->resolve(new Request(), $context));
    }
}
