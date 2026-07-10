<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Tests;

use Glueful\Extensions\Tenancy\Models\TenantDomain;
use Glueful\Extensions\Tenancy\Resolution\ResolverFactory;
use Glueful\Extensions\Tenancy\Resolution\Resolvers\DomainResolver;
use Glueful\Extensions\Tenancy\Resolution\Resolvers\SubdomainResolver;
use Glueful\Extensions\Tenancy\Tests\Support\TenancyTestCase;
use Glueful\Helpers\Utils;
use Symfony\Component\HttpFoundation\Request;

final class DomainResolverTest extends TenancyTestCase
{
    public function testExactDomainRequiresVerifiedActiveDomainAndTenant(): void
    {
        $tenant = $this->makeActiveTenant('acme');
        $domain = TenantDomain::create($this->appContext(), [
            'uuid' => Utils::generateNanoID(12),
            'tenant_uuid' => $tenant->uuid,
            'host' => 'blog.acme.test',
        ]);
        $resolver = new DomainResolver();
        $request = Request::create('https://blog.acme.test/');

        self::assertNull($resolver->resolve($request, $this->appContext()));

        $this->connection()->table('tenant_domains')->where('uuid', $domain->uuid)->update([
            'verification_status' => TenantDomain::VERIFICATION_VERIFIED,
        ]);
        self::assertSame($tenant->uuid, $resolver->resolve($request, $this->appContext()));

        $this->connection()->table('tenants')->where('uuid', $tenant->uuid)->update(['status' => 'suspended']);
        self::assertNull($resolver->resolve($request, $this->appContext()));
    }

    public function testDomainPrecedesSubdomainFallback(): void
    {
        $domainTenant = $this->makeActiveTenant('mapped');
        $this->makeActiveTenant('shop');
        $domain = TenantDomain::create($this->appContext(), [
            'uuid' => Utils::generateNanoID(12),
            'tenant_uuid' => $domainTenant->uuid,
            'host' => 'shop.example.com',
        ]);
        $this->connection()->table('tenant_domains')->where('uuid', $domain->uuid)->update([
            'verification_status' => TenantDomain::VERIFICATION_VERIFIED,
        ]);
        $this->appContext()->mergeConfigDefaults('tenancy', [
            'public_origin' => ['base_domain' => 'example.com'],
        ]);

        $resolved = ResolverFactory::chainForNames(['domain', 'subdomain'])->resolve(
            Request::create('https://shop.example.com/'),
            $this->appContext()
        );

        self::assertSame($domainTenant->uuid, $resolved);
    }

    public function testSubdomainUsesOnlyPublicOriginConfig(): void
    {
        $this->appContext()->mergeConfigDefaults('tenancy', [
            'public_origin' => ['base_domain' => 'example.com'],
            'subdomain' => ['base_domain' => 'wrong.test'],
        ]);

        self::assertSame(
            'acme',
            (new SubdomainResolver())->resolve(
                Request::create('https://acme.example.com/'),
                $this->appContext()
            )
        );
    }
}
