<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Tests;

use Glueful\Extensions\Tenancy\Models\TenantDomain;
use Glueful\Extensions\Tenancy\Tests\Support\TenancyTestCase;
use Glueful\Helpers\Utils;

final class TenantDomainSchemaTest extends TenancyTestCase
{
    public function testDomainDefaultsAndGlobalHostUniqueness(): void
    {
        $tenant = $this->makeActiveTenant('acme');
        $domain = TenantDomain::create($this->appContext(), [
            'uuid' => Utils::generateNanoID(12),
            'tenant_uuid' => $tenant->uuid,
            'host' => 'blog.acme.test',
            'verification_token' => str_repeat('a', 64),
        ]);
        $domain = TenantDomain::query($this->appContext())->where('uuid', $domain->uuid)->first();

        self::assertNotNull($domain);
        self::assertSame(TenantDomain::VERIFICATION_PENDING, $domain->verification_status);
        self::assertSame(TenantDomain::STATUS_ACTIVE, $domain->status);
        self::assertFalse($domain->isPubliclyResolvable());

        $this->expectException(\Throwable::class);
        TenantDomain::create($this->appContext(), [
            'uuid' => Utils::generateNanoID(12),
            'tenant_uuid' => $tenant->uuid,
            'host' => 'blog.acme.test',
        ]);
    }
}
