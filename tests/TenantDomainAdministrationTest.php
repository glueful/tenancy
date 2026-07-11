<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Tests;

use Glueful\Extensions\Tenancy\Bridge\ContractTenantDomainAdministration;
use Glueful\Extensions\Tenancy\Bridge\ContractTenantResolutionProbe;
use Glueful\Extensions\Tenancy\Models\TenantDomain;
use Glueful\Extensions\Tenancy\Resolution\DnsTxtLookup;
use Glueful\Extensions\Tenancy\Resolution\DnsTxtResult;
use Glueful\Extensions\Tenancy\Tests\Support\TenancyTestCase;

final class TenantDomainAdministrationTest extends TenancyTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->appContext()->mergeConfigDefaults('tenancy', require __DIR__ . '/../config/tenancy.php');
    }

    public function testAddVerifyAndProjectDomain(): void
    {
        $tenant = $this->makeActiveTenant('acme');
        $dns = new class extends DnsTxtLookup {
            /** @var list<string> */
            public array $records = [];

            public function lookupStructured(string $name): DnsTxtResult
            {
                return new DnsTxtResult('success', $this->records);
            }
        };
        $admin = new ContractTenantDomainAdministration($dns);
        $created = $admin->addDomain($this->appContext(), $tenant->uuid, 'BLOG.Acme.Test.');

        self::assertSame(TenantDomain::VERIFICATION_PENDING, $admin->verifyDomain(
            $this->appContext(),
            $created['uuid']
        ));
        $dns->records = [$created['token']];
        self::assertSame(TenantDomain::VERIFICATION_VERIFIED, $admin->verifyDomain(
            $this->appContext(),
            $created['uuid']
        ));

        $domain = $admin->getDomain($this->appContext(), $created['uuid']);
        self::assertSame($tenant->uuid, $domain['tenant_uuid']);
        self::assertSame('blog.acme.test', $domain['host']);
    }

    public function testPreverifiedHostIsIdempotentAndRejectsForeignOwner(): void
    {
        $a = $this->makeActiveTenant('a');
        $b = $this->makeActiveTenant('b');
        $admin = new ContractTenantDomainAdministration();

        $first = $admin->addPreverifiedDomain($this->appContext(), $a->uuid, 'app.example.test');
        self::assertSame(
            $first,
            $admin->addPreverifiedDomain($this->appContext(), $a->uuid, 'app.example.test')
        );

        $this->expectException(\DomainException::class);
        $admin->addPreverifiedDomain($this->appContext(), $b->uuid, 'app.example.test');
    }

    public function testProbeUsesExactDomainThenSubdomainPipeline(): void
    {
        $tenant = $this->makeActiveTenant('acme');
        $admin = new ContractTenantDomainAdministration();
        $admin->addPreverifiedDomain($this->appContext(), $tenant->uuid, 'blog.acme.test');

        self::assertSame(
            $tenant->uuid,
            (new ContractTenantResolutionProbe())->probePublicHost(
                $this->appContext(),
                'blog.acme.test'
            )
        );
        self::assertNull((new ContractTenantResolutionProbe())->probePublicHost(
            $this->appContext(),
            'missing.acme.test'
        ));
    }
}
