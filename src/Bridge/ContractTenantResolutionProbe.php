<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Bridge;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Contracts\Tenancy\TenantResolutionProbe;
use Glueful\Extensions\Tenancy\Models\Tenant;
use Glueful\Extensions\Tenancy\Resolution\ResolutionProfile;
use Glueful\Extensions\Tenancy\Resolution\ResolverFactory;
use Symfony\Component\HttpFoundation\Request;

final class ContractTenantResolutionProbe implements TenantResolutionProbe
{
    public function probePublicHost(ApplicationContext $c, string $host): ?string
    {
        $profile = ResolutionProfile::fromConfig($c, 'public');
        $candidate = ResolverFactory::chainForNames($profile->resolvers)->resolve(
            Request::create('https://' . $host . '/'),
            $c
        );
        if ($candidate === null) {
            return null;
        }
        $tenant = Tenant::query($c)->where('uuid', $candidate)->first();
        if ($tenant === null && !$profile->uuidOnly) {
            $tenant = Tenant::query($c)->where('slug', $candidate)->first();
        }

        return $tenant !== null && $tenant->isActive() ? $tenant->uuid : null;
    }
}
