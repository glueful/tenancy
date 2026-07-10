<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Resolution\Resolvers;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Tenancy\Exceptions\InvalidHostException;
use Glueful\Extensions\Tenancy\Models\Tenant;
use Glueful\Extensions\Tenancy\Models\TenantDomain;
use Glueful\Extensions\Tenancy\Resolution\HostNormalizer;
use Glueful\Extensions\Tenancy\Resolution\TenantResolverInterface;
use Symfony\Component\HttpFoundation\Request;

/** Exact verified host mapping; always precedes subdomain inference. */
final class DomainResolver implements TenantResolverInterface
{
    public function resolve(Request $request, ApplicationContext $context): ?string
    {
        try {
            $host = HostNormalizer::normalize($request->getHost());
        } catch (InvalidHostException) {
            return null;
        }

        $domain = TenantDomain::query($context)
            ->where('host', $host)
            ->where('verification_status', TenantDomain::VERIFICATION_VERIFIED)
            ->where('status', TenantDomain::STATUS_ACTIVE)
            ->first();
        if ($domain === null) {
            return null;
        }

        $tenant = Tenant::query($context)->where('uuid', $domain->tenant_uuid)->first();

        return $tenant !== null && $tenant->isActive() ? $domain->tenant_uuid : null;
    }
}
