<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Bridge;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Contracts\Tenancy\FullTenantResolutionReadiness;
use Glueful\Extensions\Contracts\Tenancy\TenantDomainAdministration;
use Glueful\Extensions\Tenancy\Models\TenantDomain;
use Glueful\Extensions\Tenancy\Resolution\DnsTxtLookup;
use Glueful\Extensions\Tenancy\Resolution\HostNormalizer;
use Glueful\Helpers\Utils;

final class ContractTenantDomainAdministration implements TenantDomainAdministration
{
    public function __construct(private readonly DnsTxtLookup $dns = new DnsTxtLookup())
    {
    }

    public function addDomain(ApplicationContext $c, string $tenantUuid, string $host): array
    {
        $host = $this->registeredHost($c, $host);
        $token = bin2hex(random_bytes(32));
        $domain = TenantDomain::create($c, [
            'uuid' => Utils::generateNanoID(12),
            'tenant_uuid' => $tenantUuid,
            'host' => $host,
            'verification_token' => $token,
        ]);

        return ['uuid' => $domain->uuid, 'token' => $token];
    }

    public function verifyDomain(ApplicationContext $c, string $domainUuid): string
    {
        $domain = TenantDomain::query($c)->where('uuid', $domainUuid)->first();
        if ($domain === null) {
            throw new \RuntimeException('Tenant domain was not found.');
        }
        $records = $this->dns->lookup('_thallo-verify.' . $domain->host);
        if (!in_array($domain->verification_token, $records, true)) {
            return TenantDomain::VERIFICATION_PENDING;
        }
        db($c)->table('tenant_domains')->where('uuid', $domainUuid)->update([
            'verification_status' => TenantDomain::VERIFICATION_VERIFIED,
            'verified_at' => gmdate('Y-m-d H:i:s'),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);

        return TenantDomain::VERIFICATION_VERIFIED;
    }

    public function disableDomain(ApplicationContext $c, string $domainUuid): void
    {
        $this->assertNotRequiredHost($c, $domainUuid);
        $this->setStatus($c, $domainUuid, TenantDomain::STATUS_DISABLED);
    }

    public function enableDomain(ApplicationContext $c, string $domainUuid): void
    {
        $this->setStatus($c, $domainUuid, TenantDomain::STATUS_ACTIVE);
    }

    public function removeDomain(ApplicationContext $c, string $domainUuid): void
    {
        $this->assertNotRequiredHost($c, $domainUuid);
        $deleted = db($c)->table('tenant_domains')->where('uuid', $domainUuid)->delete();
        if ($deleted === 0) {
            throw new \RuntimeException('Tenant domain was not found.');
        }
    }

    public function listDomains(ApplicationContext $c, string $tenantUuid): array
    {
        $result = [];
        $domains = TenantDomain::query($c)
            ->where('tenant_uuid', $tenantUuid)
            ->orderBy('created_at', 'asc')
            ->orderBy('uuid', 'asc')
            ->get();
        foreach ($domains as $domain) {
            $result[] = $this->projection($domain, false);
        }

        return $result;
    }

    public function getDomain(ApplicationContext $c, string $domainUuid): ?array
    {
        $domain = TenantDomain::query($c)->where('uuid', $domainUuid)->first();

        return $domain === null ? null : $this->projection($domain, true);
    }

    public function addPreverifiedDomain(
        ApplicationContext $c,
        string $tenantUuid,
        string $host
    ): string {
        $host = HostNormalizer::normalize($host);
        HostNormalizer::validateForRegistration(
            $host,
            (array) config($c, 'tenancy.public_origin', []),
            true
        );
        $existing = TenantDomain::query($c)->where('host', $host)->first();
        if ($existing !== null) {
            if ($existing->tenant_uuid !== $tenantUuid) {
                throw new \DomainException('Host is already owned by another tenant.');
            }
            db($c)->table('tenant_domains')->where('uuid', $existing->uuid)->update([
                'verification_status' => TenantDomain::VERIFICATION_VERIFIED,
                'status' => TenantDomain::STATUS_ACTIVE,
                'verified_at' => $existing->verified_at ?? gmdate('Y-m-d H:i:s'),
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ]);
            return $existing->uuid;
        }

        $domain = TenantDomain::create($c, [
            'uuid' => Utils::generateNanoID(12),
            'tenant_uuid' => $tenantUuid,
            'host' => $host,
        ]);
        db($c)->table('tenant_domains')->where('uuid', $domain->uuid)->update([
            'verification_status' => TenantDomain::VERIFICATION_VERIFIED,
            'status' => TenantDomain::STATUS_ACTIVE,
            'verified_at' => gmdate('Y-m-d H:i:s'),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);

        return $domain->uuid;
    }

    private function registeredHost(ApplicationContext $c, string $host): string
    {
        $host = HostNormalizer::normalize($host);
        HostNormalizer::validateForRegistration($host, (array) config($c, 'tenancy.public_origin', []));

        return $host;
    }

    private function setStatus(ApplicationContext $c, string $domainUuid, string $status): void
    {
        $changed = db($c)->table('tenant_domains')->where('uuid', $domainUuid)->update([
            'status' => $status,
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
        if ($changed === 0 && $this->getDomain($c, $domainUuid) === null) {
            throw new \RuntimeException('Tenant domain was not found.');
        }
    }

    private function assertNotRequiredHost(ApplicationContext $c, string $domainUuid): void
    {
        $container = $c->getContainer();
        if (!$container->has(FullTenantResolutionReadiness::class)) {
            return;
        }
        $readiness = $container->get(FullTenantResolutionReadiness::class);
        if (!$readiness instanceof FullTenantResolutionReadiness || !$readiness->isReady($c)) {
            return;
        }
        $domain = $this->getDomain($c, $domainUuid);
        if ($domain === null) {
            throw new \RuntimeException('Tenant domain was not found.');
        }
        $configured = config($c, 'tenancy.public_origin.default_hosts', []);
        $required = [];
        foreach (is_array($configured) ? $configured : [] as $host) {
            if (is_string($host)) {
                $required[] = HostNormalizer::normalize($host);
            }
        }
        if (in_array($domain['host'], $required, true)) {
            throw new \DomainException('A required default host cannot be disabled or removed.');
        }
    }

    /** @return array<string,string> */
    private function projection(TenantDomain $domain, bool $withTenant): array
    {
        $result = [
            'uuid' => $domain->uuid,
            'host' => $domain->host,
            'verification_status' => $domain->verification_status,
            'status' => $domain->status,
        ];
        if ($withTenant) {
            $result = ['tenant_uuid' => $domain->tenant_uuid] + $result;
        }

        return $result;
    }
}
