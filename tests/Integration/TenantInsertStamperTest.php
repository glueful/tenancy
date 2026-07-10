<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Tests\Integration;

use Glueful\Extensions\Tenancy\Context\CurrentContext;
use Glueful\Extensions\Tenancy\Context\TenantContext;
use Glueful\Extensions\Tenancy\Exceptions\TenantScopeViolationException;
use Glueful\Extensions\Tenancy\Models\Tenant;
use Glueful\Extensions\Tenancy\Query\TenantInsertStamper;
use Glueful\Extensions\Tenancy\Query\TenantTableRegistry;
use Glueful\Extensions\Tenancy\Tests\Support\TenancyTestCase;

final class TenantInsertStamperTest extends TenancyTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        TenantTableRegistry::register('posts'); // a tenant-owned table for the test
    }

    protected function tearDown(): void
    {
        CurrentContext::clear();
        TenantTableRegistry::clear();
        parent::tearDown();
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    private function stamp(string $table, array $data): array
    {
        return (TenantInsertStamper::hook())($table, $data);
    }

    private function makeCurrentTenant(string $uuid): void
    {
        Tenant::create($this->appContext(), ['uuid' => $uuid, 'slug' => 's', 'name' => 'N']);
        CurrentContext::set($this->appContext());
        (new TenantContext($this->appContext()))->setTenant(
            Tenant::query($this->appContext())->where('uuid', $uuid)->first()
        );
    }

    public function testStampsMissingTenantUuidFromCurrentTenant(): void
    {
        $this->makeCurrentTenant('tenaaaaaaaa1');
        self::assertSame('tenaaaaaaaa1', $this->stamp('posts', ['title' => 'x'])['tenant_uuid']);
    }

    public function testKeepsSuppliedTenantUuidWhenItMatchesCurrent(): void
    {
        $this->makeCurrentTenant('tenaaaaaaaa1');
        self::assertSame(
            'tenaaaaaaaa1',
            $this->stamp('posts', ['title' => 'x', 'tenant_uuid' => 'tenaaaaaaaa1'])['tenant_uuid']
        );
    }

    public function testRejectsSuppliedWrongTenantUuid(): void
    {
        $this->makeCurrentTenant('tenaaaaaaaa1');
        $this->expectException(TenantScopeViolationException::class);
        $this->stamp('posts', ['title' => 'x', 'tenant_uuid' => 'tenzzzzzzzz9']);
    }

    public function testBypassAllowsCrossTenantSuppliedUuid(): void
    {
        CurrentContext::set($this->appContext());
        (new TenantContext($this->appContext()))->setBypass('system');

        // Under system bypass the stamper is a no-op — a supplied uuid passes through untouched.
        self::assertSame(
            'tenzzzzzzzz9',
            $this->stamp('posts', ['title' => 'x', 'tenant_uuid' => 'tenzzzzzzzz9'])['tenant_uuid']
        );
    }

    public function testNonTenantTableUnchanged(): void
    {
        CurrentContext::set($this->appContext());
        self::assertArrayNotHasKey('tenant_uuid', $this->stamp('unregistered_table', ['a' => 1]));
    }

    public function testNoCurrentContextIsNoOp(): void
    {
        CurrentContext::clear();
        self::assertArrayNotHasKey('tenant_uuid', $this->stamp('posts', ['title' => 'x']));
    }

    public function testBypassIsNoOp(): void
    {
        CurrentContext::set($this->appContext());
        (new TenantContext($this->appContext()))->setBypass('system');
        self::assertArrayNotHasKey('tenant_uuid', $this->stamp('posts', ['title' => 'x']));
    }

    public function testFailsClosedWhenContextButNoTenant(): void
    {
        CurrentContext::set($this->appContext()); // live context, no bypass, no tenant.tenant
        $this->expectException(TenantScopeViolationException::class);
        $this->stamp('posts', ['title' => 'x']);
    }
}
