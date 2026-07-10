<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Resolution\Resolvers;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Tenancy\Resolution\TenantResolverInterface;
use Glueful\Extensions\Tenancy\Resolution\HostNormalizer;
use Glueful\Extensions\Tenancy\Exceptions\InvalidHostException;
use Symfony\Component\HttpFoundation\Request;

/**
 * Resolves a tenant candidate from the left-most subdomain label of the host.
 *
 * Requires a configured base domain (tenancy.public_origin.base_domain). For host
 * `acme.app.com` with base `app.com` the candidate is `acme`. Returns null when
 * no base is configured, when the host equals the base, or when there is no
 * subdomain label.
 */
final class SubdomainResolver implements TenantResolverInterface
{
    public function resolve(Request $request, ApplicationContext $context): ?string
    {
        $base = config($context, 'tenancy.public_origin.base_domain');

        if (!is_string($base) || $base === '') {
            return null;
        }

        try {
            $host = HostNormalizer::normalize($request->getHost());
            $base = HostNormalizer::normalize($base);
        } catch (InvalidHostException) {
            return null;
        }

        if ($host === $base) {
            return null;
        }

        $suffix = '.' . $base;

        if (!str_ends_with($host, $suffix)) {
            return null;
        }

        $prefix = substr($host, 0, -strlen($suffix));

        if ($prefix === '') {
            return null;
        }

        $labels = explode('.', $prefix);

        return $labels[0] !== '' ? $labels[0] : null;
    }
}
