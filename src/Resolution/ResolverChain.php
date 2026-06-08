<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Resolution;

use Glueful\Bootstrap\ApplicationContext;
use Symfony\Component\HttpFoundation\Request;

/**
 * Runs an ordered list of tenant resolvers and returns the first non-null candidate.
 */
final class ResolverChain
{
    /** @param list<TenantResolverInterface> $resolvers ordered */
    public function __construct(private array $resolvers)
    {
    }

    public function resolve(Request $request, ApplicationContext $context): ?string
    {
        foreach ($this->resolvers as $resolver) {
            $candidate = $resolver->resolve($request, $context);
            if ($candidate !== null && $candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }
}
