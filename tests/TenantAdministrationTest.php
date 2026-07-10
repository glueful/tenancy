<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Tests;

use Glueful\Extensions\Tenancy\Bridge\ContractTenantAdministration;
use Glueful\Extensions\Tenancy\Models\Tenant;
use Glueful\Extensions\Tenancy\Models\TenantMembership;
use Glueful\Extensions\Tenancy\Tests\Support\TenancyTestCase;
use Glueful\Helpers\Utils;

final class TenantAdministrationTest extends TenancyTestCase
{
    private ContractTenantAdministration $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->appContext()->mergeConfigDefaults('tenancy', require __DIR__ . '/../config/tenancy.php');
        $this->admin = new ContractTenantAdministration();
    }

    public function testCreateIsAtomicAndStartsProvisioningWithOwner(): void
    {
        $owner = Utils::generateNanoID(12);
        $uuid = $this->admin->create($this->appContext(), 'acme', 'Acme', $owner);

        self::assertSame('provisioning', $this->admin->getTenant($this->appContext(), $uuid)['status']);
        self::assertSame('owner', $this->admin->listMembers($this->appContext(), $uuid)[0]['role']);
        self::assertSame([], $this->admin->listTenantsForUser($this->appContext(), $owner));

        $this->admin->markActive($this->appContext(), $uuid);
        self::assertCount(1, $this->admin->listTenantsForUser($this->appContext(), $owner));
    }

    public function testCreateRollsBackTenantWhenOwnerInsertFails(): void
    {
        $this->connection()->getPDO()->exec(
            "CREATE TRIGGER fail_membership BEFORE INSERT ON tenant_memberships "
            . "BEGIN SELECT RAISE(ABORT, 'membership failed'); END"
        );

        try {
            $this->admin->create($this->appContext(), 'rollback', 'Rollback', Utils::generateNanoID(12));
            self::fail('Create should fail when owner creation fails.');
        } catch (\Throwable) {
            self::assertNull(Tenant::query($this->appContext())->where('slug', 'rollback')->first());
        }
    }

    public function testFinalActiveOwnerCannotBeRemovedOrDemoted(): void
    {
        $owner = Utils::generateNanoID(12);
        $uuid = $this->admin->create($this->appContext(), 'owners', 'Owners', $owner);

        foreach (['remove', 'demote'] as $operation) {
            try {
                if ($operation === 'remove') {
                    $this->admin->removeMember($this->appContext(), $uuid, $owner);
                } else {
                    $this->admin->setMemberRole($this->appContext(), $uuid, $owner, 'admin');
                }
                self::fail('The final owner mutation should fail.');
            } catch (\DomainException) {
                self::addToAssertionCount(1);
            }
        }

        $other = Utils::generateNanoID(12);
        $this->admin->addMember($this->appContext(), $uuid, $other, 'owner');
        $this->admin->removeMember($this->appContext(), $uuid, $owner);
        self::assertNull(TenantMembership::query($this->appContext())
            ->where('tenant_uuid', $uuid)->where('user_uuid', $owner)->first());
    }

    public function testReservedAndMalformedSlugsAreRejected(): void
    {
        foreach (['www', '--bad'] as $slug) {
            try {
                $this->admin->create($this->appContext(), $slug, 'Bad', Utils::generateNanoID(12));
                self::fail('Invalid slug should be rejected.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
