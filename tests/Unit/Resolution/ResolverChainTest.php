<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Tests\Unit\Resolution;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Tenancy\Resolution\ResolverChain;
use Glueful\Extensions\Tenancy\Resolution\TenantResolverInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class ResolverChainTest extends TestCase
{
    private function resolverReturning(?string $value): TenantResolverInterface
    {
        return new class ($value) implements TenantResolverInterface {
            public function __construct(private ?string $value)
            {
            }

            public function resolve(Request $request, ApplicationContext $context): ?string
            {
                return $this->value;
            }
        };
    }

    private function context(): ApplicationContext
    {
        return new ApplicationContext(basePath: sys_get_temp_dir(), environment: 'testing');
    }

    public function test_chain_returns_first_non_null_candidate(): void
    {
        $chain = new ResolverChain([
            $this->resolverReturning(null),
            $this->resolverReturning('acme'),
            $this->resolverReturning('other'),
        ]);

        $this->assertSame('acme', $chain->resolve(new Request(), $this->context()));
    }

    public function test_empty_chain_returns_null(): void
    {
        $chain = new ResolverChain([]);

        $this->assertNull($chain->resolve(new Request(), $this->context()));
    }
}
