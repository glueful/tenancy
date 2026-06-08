<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Resolution\Resolvers;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Tenancy\Resolution\TenantResolverInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Resolves a tenant candidate from a configurable request header.
 */
final class HeaderResolver implements TenantResolverInterface
{
    public function resolve(Request $request, ApplicationContext $context): ?string
    {
        $name = (string) config($context, 'tenancy.header.name', 'X-Tenant-Id');

        return $request->headers->get($name);
    }
}
