<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Tests\Unit\Resolution;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Tenancy\Resolution\ResolverFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * ResolverFactory turns the ordered config name-list (config('tenancy.resolvers'))
 * into a concrete {@see \Glueful\Extensions\Tenancy\Resolution\ResolverChain}. The
 * chain wires the right resolver implementations, in the configured order, and skips
 * unknown names rather than blowing up.
 */
final class ResolverFactoryTest extends TestCase
{
    private function contextWithResolvers(array $resolvers): ApplicationContext
    {
        $context = new ApplicationContext(basePath: sys_get_temp_dir(), environment: 'testing');
        $context->mergeConfigDefaults('tenancy', [
            'resolvers' => $resolvers,
            'header' => ['name' => 'X-Tenant-Id'],
            'query' => ['name' => 'tenant_id'],
        ]);

        return $context;
    }

    public function test_chain_resolves_header_candidate_from_configured_resolvers(): void
    {
        $context = $this->contextWithResolvers(['header', 'query']);
        $chain = ResolverFactory::chain($context);

        $request = Request::create('/');
        $request->headers->set('X-Tenant-Id', 'acme');

        $this->assertSame('acme', $chain->resolve($request, $context));
    }

    public function test_chain_resolves_query_candidate_when_header_absent(): void
    {
        $context = $this->contextWithResolvers(['header', 'query']);
        $chain = ResolverFactory::chain($context);

        $request = Request::create('/?tenant_id=beta');

        $this->assertSame('beta', $chain->resolve($request, $context));
    }

    public function test_unknown_resolver_names_are_skipped(): void
    {
        $context = $this->contextWithResolvers(['bogus', 'header']);
        $chain = ResolverFactory::chain($context);

        $request = Request::create('/');
        $request->headers->set('X-Tenant-Id', 'gamma');

        $this->assertSame('gamma', $chain->resolve($request, $context));
    }
}
