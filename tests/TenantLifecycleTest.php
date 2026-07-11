<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Tests;

use Glueful\Extensions\Tenancy\Bridge\ContractTenantAdministration;
use Glueful\Extensions\Tenancy\Cooldown\ReleasedHostRepository;
use Glueful\Extensions\Tenancy\Exceptions\FinalWorkspaceException;
use Glueful\Extensions\Tenancy\Exceptions\TenantLifecycleException;
use Glueful\Extensions\Tenancy\Tests\Support\TenancyTestCase;
use Glueful\Helpers\Utils;

final class TenantLifecycleTest extends TenancyTestCase
{
    private ContractTenantAdministration $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->appContext()->mergeConfigDefaults('tenancy', require __DIR__ . '/../config/tenancy.php');
        $this->admin = new ContractTenantAdministration(new ReleasedHostRepository());
    }

    public function testDeleteAndRestoreRecoverSuspendedStatus(): void
    {
        $this->active('keep');
        $target = $this->active('restore');
        $this->admin->suspend($this->appContext(), $target);

        $this->admin->deleteTenant($this->appContext(), $target);
        self::assertNull($this->admin->getTenant($this->appContext(), $target));
        $deleted = $this->admin->getTenantLifecycle($this->appContext(), $target);
        self::assertSame('deleted', $deleted['status']);
        self::assertSame('suspended', $deleted['deleted_from_status']);
        self::assertNotNull($deleted['purge_after']);
        self::assertSame('deleted', $this->admin->listTenants($this->appContext(), 'deleted')[0]['status']);

        $this->admin->restoreTenant($this->appContext(), $target);
        self::assertSame('suspended', $this->admin->getTenant($this->appContext(), $target)['status']);
    }

    public function testExpiredRestoreAndRestoreAfterBeginPurgeAreRefused(): void
    {
        $this->active('keep');
        $target = $this->active('expired');
        $this->admin->deleteTenant($this->appContext(), $target);
        $this->connection()->table('tenants')->where('uuid', $target)
            ->update(['purge_after' => '2000-01-01 00:00:00']);
        try {
            $this->admin->restoreTenant($this->appContext(), $target);
            self::fail('Expired trash must not restore.');
        } catch (TenantLifecycleException) {
            self::addToAssertionCount(1);
        }
        $this->connection()->table('tenants')->where('uuid', $target)
            ->update(['purge_after' => '2999-01-01 00:00:00']);
        $this->admin->beginPurge($this->appContext(), $target);
        $this->expectException(TenantLifecycleException::class);
        $this->admin->restoreTenant($this->appContext(), $target);
    }

    public function testFinalWorkspaceCannotBeDeleted(): void
    {
        $target = $this->active('final');
        $this->expectException(FinalWorkspaceException::class);
        $this->admin->deleteTenant($this->appContext(), $target);
    }

    public function testFinalPurgeHardDeletesTenantAndTombstonesHost(): void
    {
        $this->active('keep');
        $target = $this->active('purge');
        $domain = new \Glueful\Extensions\Tenancy\Bridge\ContractTenantDomainAdministration();
        $domain->addPreverifiedDomain($this->appContext(), $target, 'purge.example.test');
        $this->admin->deleteTenant($this->appContext(), $target);
        $this->admin->beginPurge($this->appContext(), $target);
        $this->admin->purgeTenantRecord($this->appContext(), $target);

        self::assertNull($this->admin->getTenantLifecycle($this->appContext(), $target));
        self::assertNotNull((new ReleasedHostRepository())->activeTombstone(
            $this->appContext(),
            'purge.example.test',
            gmdate('Y-m-d H:i:s'),
        ));
    }

    private function active(string $slug): string
    {
        $uuid = $this->admin->create($this->appContext(), $slug, ucfirst($slug), Utils::generateNanoID(12));
        $this->admin->markActive($this->appContext(), $uuid);

        return $uuid;
    }
}
