<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Tests\Integration;

use Glueful\Extensions\Tenancy\Context\TenantContext;
use Glueful\Extensions\Tenancy\Exceptions\MissingTenantContextException;
use Glueful\Extensions\Tenancy\Models\Tenant;
use Glueful\Extensions\Tenancy\Query\TenantTableRegistry;
use Glueful\Extensions\Tenancy\Tests\Support\FillableProject;
use Glueful\Extensions\Tenancy\Tests\Support\Project;
use Glueful\Extensions\Tenancy\Tests\Support\TenancyTestCase;
use Glueful\Helpers\Utils;

/**
 * Integration coverage for the automatic ORM scoping core: the BelongsToTenant
 * trait + TenantScope (scoped reads, stamped + immutable writes, fail-closed, and
 * the noisy bypass macros).
 */
final class BelongsToTenantTest extends TenancyTestCase
{
    private Tenant $tenantA;
    private Tenant $tenantB;
    private int $bProjectId;

    protected function setUp(): void
    {
        parent::setUp();

        // Tenant-owned fixture table.
        $this->connection()->getSchemaBuilder()->createTable('projects', function ($table) {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('uuid', 12);
            $table->string('tenant_uuid', 12);
            $table->string('name', 255);

            $table->index('tenant_uuid');
        });

        // Same shape as `projects`, but its model lists tenant_uuid in $fillable.
        $this->connection()->getSchemaBuilder()->createTable('fillable_projects', function ($table) {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('uuid', 12);
            $table->string('tenant_uuid', 12);
            $table->string('name', 255);

            $table->index('tenant_uuid');
        });

        $this->tenantA = $this->makeActiveTenant('alpha');
        $this->tenantB = $this->makeActiveTenant('beta');

        // Seed one project per tenant via raw inserts (bypassing the scope/stamping).
        $this->connection()->table('projects')->insert([
            'uuid' => Utils::generateNanoID(12),
            'tenant_uuid' => $this->tenantA->uuid,
            'name' => 'A-project',
        ]);
        $bUuid = Utils::generateNanoID(12);
        $this->connection()->table('projects')->insert([
            'uuid' => $bUuid,
            'tenant_uuid' => $this->tenantB->uuid,
            'name' => 'B-project',
        ]);
        // The query-builder insert() returns an affected-row count, not the last
        // insert id, so read B's primary key back explicitly.
        $this->bProjectId = (int) $this->connection()->table('projects')
            ->where('uuid', $bUuid)->first()['id'];
    }

    private function tenancy(): TenantContext
    {
        return new TenantContext($this->appContext());
    }

    public function testScopedReadsOnlyReturnCurrentTenantRows(): void
    {
        $this->tenancy()->setTenant($this->tenantA);
        $ctx = $this->appContext();

        $all = Project::all($ctx);
        self::assertCount(1, $all);
        self::assertSame($this->tenantA->uuid, $all->first()->tenant_uuid);

        // B's project is invisible to A.
        self::assertNull(Project::find($ctx, $this->bProjectId));
    }

    public function testCreateStampsTenantUuidFromContext(): void
    {
        $this->tenancy()->setTenant($this->tenantA);
        $ctx = $this->appContext();

        $project = Project::create($ctx, [
            'uuid' => Utils::generateNanoID(12),
            'name' => 'new-one',
        ]);

        self::assertSame($this->tenantA->uuid, $project->tenant_uuid);

        // Persisted with the right tenant.
        $row = $this->connection()->table('projects')->where('id', $project->id)->first();
        self::assertSame($this->tenantA->uuid, $row['tenant_uuid']);
    }

    /**
     * Security regression: a caller-supplied `tenant_uuid` must NEVER override the
     * active tenant on create, even when the consumer model exposes `tenant_uuid`
     * to mass assignment ($fillable). Acting in tenant A, an attacker payload
     * carrying tenant B's uuid must be force-overwritten to A — not planted in B.
     */
    public function testCreateForcesTenantUuidEvenWhenSuppliedAndFillable(): void
    {
        $this->tenancy()->setTenant($this->tenantA);
        $ctx = $this->appContext();

        $project = FillableProject::create($ctx, [
            'uuid' => Utils::generateNanoID(12),
            'tenant_uuid' => $this->tenantB->uuid, // attacker-supplied victim tenant
            'name' => 'planted',
        ]);

        // In-memory model reflects the forced tenant.
        self::assertSame($this->tenantA->uuid, $project->tenant_uuid);

        // Persisted row belongs to A, NOT the supplied B.
        $row = $this->connection()->table('fillable_projects')->where('id', $project->id)->first();
        self::assertSame($this->tenantA->uuid, $row['tenant_uuid']);
        self::assertNotSame($this->tenantB->uuid, $row['tenant_uuid']);
    }

    /**
     * Under an explicit bypass mode (privileged forAnyTenant/runAsSystem code), a
     * caller-supplied `tenant_uuid` IS honored — privileged callers control it.
     */
    public function testExplicitBypassHonorsSuppliedTenantUuid(): void
    {
        $this->tenancy()->setBypass('forAnyTenant');
        $ctx = $this->appContext();

        $project = FillableProject::create($ctx, [
            'uuid' => Utils::generateNanoID(12),
            'tenant_uuid' => $this->tenantB->uuid,
            'name' => 'privileged-cross-tenant',
        ]);

        self::assertSame($this->tenantB->uuid, $project->tenant_uuid);

        $row = $this->connection()->table('fillable_projects')->where('id', $project->id)->first();
        self::assertSame($this->tenantB->uuid, $row['tenant_uuid']);
    }

    public function testCreateWithoutTenantThrows(): void
    {
        $this->tenancy()->clear();
        $ctx = $this->appContext();

        $this->expectException(MissingTenantContextException::class);

        Project::create($ctx, [
            'uuid' => Utils::generateNanoID(12),
            'name' => 'orphan',
        ]);
    }

    public function testTenantUuidIsImmutableOnUpdate(): void
    {
        $this->tenancy()->setTenant($this->tenantA);
        $ctx = $this->appContext();

        $project = Project::query($ctx)->where('tenant_uuid', $this->tenantA->uuid)->first();
        self::assertNotNull($project);

        $project->tenant_uuid = $this->tenantB->uuid;

        $this->expectException(MissingTenantContextException::class);
        $project->save();
    }

    public function testTableIsRegisteredAfterBoot(): void
    {
        // Force the model to boot.
        Project::query($this->appContext());

        self::assertTrue(TenantTableRegistry::isTenantOwned('projects'));
    }

    public function testBypassReturnsRowsAcrossTenants(): void
    {
        $this->tenancy()->setBypass('forAnyTenant');
        $ctx = $this->appContext();

        $all = Project::all($ctx);
        self::assertCount(2, $all);

        // Explicit macro also runs unscoped.
        $this->tenancy()->clear();
        $viaMacro = Project::withoutTenantScope($ctx)->get();
        self::assertCount(2, $viaMacro);
    }

    public function testFailClosedWhenNoTenantAndNoBypass(): void
    {
        $this->tenancy()->clear();
        $ctx = $this->appContext();

        $this->expectException(MissingTenantContextException::class);
        Project::all($ctx);
    }
}
