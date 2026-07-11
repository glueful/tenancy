<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Tests;

use Glueful\Extensions\Contracts\Tenancy\HostCooldownException;
use Glueful\Extensions\Tenancy\Bridge\ContractTenantDomainAdministration;
use Glueful\Extensions\Tenancy\Cooldown\ReleasedHostRepository;
use Glueful\Extensions\Tenancy\Tests\Support\TenancyTestCase;

final class HostCooldownTest extends TenancyTestCase
{
    private ReleasedHostRepository $cooldown;
    private ContractTenantDomainAdministration $domains;

    protected function setUp(): void
    {
        parent::setUp();
        $this->appContext()->mergeConfigDefaults('tenancy', require __DIR__ . '/../config/tenancy.php');
        $this->cooldown = new ReleasedHostRepository();
        $this->domains = new ContractTenantDomainAdministration(cooldown: $this->cooldown);
    }

    public function testForeignClaimIsBlockedAndOriginalOwnerCanReclaim(): void
    {
        $owner = $this->makeActiveTenant('owner');
        $other = $this->makeActiveTenant('other');
        $host = 'cooldown.example.test';
        $uuid = $this->domains->addPreverifiedDomain($this->appContext(), $owner->uuid, $host);
        $this->domains->removeDomain($this->appContext(), $uuid);

        try {
            $this->domains->addDomain($this->appContext(), $other->uuid, $host);
            self::fail('A foreign tenant must not claim an active cooldown.');
        } catch (HostCooldownException $exception) {
            self::assertNotSame('', $exception->availableAfter());
        }

        $this->domains->addDomain($this->appContext(), $owner->uuid, $host);
        self::assertNull($this->cooldown->activeTombstone(
            $this->appContext(),
            $host,
            gmdate('Y-m-d H:i:s'),
        ));
    }

    public function testOverrideAtomicallyCreatesPendingDomain(): void
    {
        $owner = $this->makeActiveTenant('owner');
        $target = $this->makeActiveTenant('target');
        $host = 'override.example.test';
        $uuid = $this->domains->addPreverifiedDomain($this->appContext(), $owner->uuid, $host);
        $this->domains->removeDomain($this->appContext(), $uuid);

        $result = $this->domains->overrideCooldownAndClaim($this->appContext(), $target->uuid, $host);
        $domain = $this->domains->getDomain($this->appContext(), $result['uuid']);
        self::assertSame($target->uuid, $domain['tenant_uuid']);
        self::assertSame('pending', $domain['verification_status']);
        self::assertNull($this->cooldown->activeTombstone(
            $this->appContext(),
            $host,
            gmdate('Y-m-d H:i:s'),
        ));
    }

    public function testTombstoneNeverShortensAndCannotTransferOwner(): void
    {
        $c = $this->appContext();
        db($c)->transaction(function () use ($c): void {
            $this->cooldown->lockHost($c, 'ledger.example.test');
            $this->cooldown->upsertTombstone(
                $c, 'ledger.example.test', 'tenantAAAAAA', '2999-01-01 00:00:00'
            );
            $this->cooldown->upsertTombstone(
                $c, 'ledger.example.test', 'tenantAAAAAA', '2099-01-01 00:00:00'
            );
        });
        self::assertStringStartsWith('2999-01-01', $this->cooldown->activeTombstone(
            $c, 'ledger.example.test', '2026-01-01 00:00:00'
        )['retained_until']);

        $this->expectException(\LogicException::class);
        db($c)->transaction(function () use ($c): void {
            $this->cooldown->lockHost($c, 'ledger.example.test');
            $this->cooldown->upsertTombstone(
                $c, 'ledger.example.test', 'tenantBBBBBB', '2999-02-01 00:00:00'
            );
        });
    }
}
