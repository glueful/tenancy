<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Resolution\Resolvers;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Tenancy\Resolution\TenantResolverInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Resolves a tenant candidate from JWT claims.
 *
 * Reads the claims array from the `jwt.claims` request attribute (populated by
 * the framework's AuthToRequestAttributesMiddleware after authentication) and
 * returns the configured claim value.
 */
final class JwtClaimResolver implements TenantResolverInterface
{
    public function resolve(Request $request, ApplicationContext $context): ?string
    {
        $claim = (string) config($context, 'tenancy.jwt.claim', 'tenant_id');

        $claims = $request->attributes->get('jwt.claims', []);

        if (!is_array($claims) || !array_key_exists($claim, $claims)) {
            return null;
        }

        $value = $claims[$claim];

        return $value !== null ? (string) $value : null;
    }
}
