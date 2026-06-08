<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Resolution\Resolvers;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Tenancy\Resolution\TenantResolverInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Resolves a tenant candidate from a configurable query parameter.
 */
final class QueryResolver implements TenantResolverInterface
{
    public function resolve(Request $request, ApplicationContext $context): ?string
    {
        $name = (string) config($context, 'tenancy.query.name', 'tenant_id');

        $value = $request->query->get($name);

        return $value !== null ? (string) $value : null;
    }
}
