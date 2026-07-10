<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Tests\Integration;

use Glueful\Database\Connection;
use Glueful\Extensions\Tenancy\Context\CurrentContext;
use Glueful\Extensions\Tenancy\Context\TenantContext;
use Glueful\Extensions\Tenancy\Exceptions\TenantScopeViolationException;
use Glueful\Extensions\Tenancy\Models\Tenant;
use Glueful\Extensions\Tenancy\Query\TenantInsertStamper;
use Glueful\Extensions\Tenancy\Query\TenantTableRegistry;
use Glueful\Extensions\Tenancy\Tests\Support\TenancyTestCase;

/**
 * End-to-end proof that a REAL builder insert against a tenant-owned table is stamped through
 * the framework Connection::addInsertHook seam (A2) + the stamper (A4) — the composition that
 * TenancyServiceProvider::boot() wires. Requires a framework build carrying the insert-hook
 * seam (pinned at release); skips cleanly on an older framework.
 */
final class TenantInsertStampingE2ETest extends TenancyTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (!method_exists(Connection::class, 'addInsertHook')) {
            self::markTestSkipped('Framework lacks Connection::addInsertHook (A2) — pinned at release.');
        }

        // Real tenant-owned table in the harness DB.
        $this->connection()->getPDO()->exec(
            'CREATE TABLE posts (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT, tenant_uuid TEXT)'
        );

        // Wire exactly what boot() wires: register the table + the stamper hook.
        TenantTableRegistry::register('posts');
        Connection::addInsertHook(TenantInsertStamper::hook());
    }

    protected function tearDown(): void
    {
        Connection::clearInsertHooks();
        TenantTableRegistry::clear();
        CurrentContext::clear();
        parent::tearDown();
    }

    private function activateTenant(string $uuid): void
    {
        Tenant::create($this->appContext(), ['uuid' => $uuid, 'slug' => 's', 'name' => 'N']);
        CurrentContext::set($this->appContext());
        (new TenantContext($this->appContext()))->setTenant(
            Tenant::query($this->appContext())->where('uuid', $uuid)->first()
        );
    }

    public function testRealBuilderInsertIsStampedWithCurrentTenant(): void
    {
        $this->activateTenant('tenaaaaaaaa1');

        $this->connection()->table('posts')->insert(['title' => 'hello']);

        $row = $this->connection()->table('posts')->where('title', 'hello')->first();
        self::assertSame('tenaaaaaaaa1', $row['tenant_uuid'], 'insert was stamped end-to-end');
    }

    public function testRealBuilderInsertRejectsCrossTenantUuid(): void
    {
        $this->activateTenant('tenaaaaaaaa1');

        $this->expectException(TenantScopeViolationException::class);
        $this->connection()->table('posts')->insert(['title' => 'x', 'tenant_uuid' => 'tenzzzzzzzz9']);
    }
}
