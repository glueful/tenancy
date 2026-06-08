<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Tests\Integration;

use Glueful\Extensions\Tenancy\Console\ActivateTenantCommand;
use Glueful\Extensions\Tenancy\Console\CreateTenantCommand;
use Glueful\Extensions\Tenancy\Console\DiagnoseTenancyCommand;
use Glueful\Extensions\Tenancy\Console\ListTenantsCommand;
use Glueful\Extensions\Tenancy\Console\SuspendTenantCommand;
use Glueful\Extensions\Tenancy\Query\TenantTableRegistry;
use Glueful\Extensions\Tenancy\Tests\Support\TenancyTestCase;
use Glueful\Helpers\Utils;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Task 8.1 — the five tenant:* console commands.
 *
 * Each command is constructed with NO container (so BaseCommand does not build a default
 * production context), then its protected {@see Command::$context}/{@see Command::$container}
 * are re-pointed at the migrated in-memory-SQLite harness via reflection. That guarantees
 * `db($this->getContext())` inside the command resolves the SAME Connection the test seeded.
 */
final class ConsoleCommandsTest extends TenancyTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        TenantTableRegistry::clear();
    }

    protected function tearDown(): void
    {
        TenantTableRegistry::clear();
        parent::tearDown();
    }

    /**
     * Point a freshly built command at the harness context + container so its db() calls
     * land on the migrated in-memory SQLite connection.
     */
    private function bind(Command $command): void
    {
        $ctx = $this->appContext();
        $container = $ctx->getContainer();

        $ref = new \ReflectionObject($command);
        $ctxProp = $ref->getProperty('context');
        $ctxProp->setAccessible(true);
        $ctxProp->setValue($command, $ctx);

        $containerProp = $ref->getProperty('container');
        $containerProp->setAccessible(true);
        $containerProp->setValue($command, $container);
    }

    public function testCreateInsertsActiveTenant(): void
    {
        $command = new CreateTenantCommand();
        $this->bind($command);

        $tester = new CommandTester($command);
        $exit = $tester->execute(['--slug' => 'acme', '--name' => 'Acme Inc']);

        self::assertSame(Command::SUCCESS, $exit);

        $row = $this->connection()->table('tenants')->where('slug', 'acme')->first();
        self::assertNotNull($row);
        self::assertSame('Acme Inc', $row['name']);
        self::assertSame('active', $row['status']);
        self::assertNotEmpty($row['uuid']);
    }

    public function testCreateRejectsDuplicateSlug(): void
    {
        $first = new CreateTenantCommand();
        $this->bind($first);
        (new CommandTester($first))->execute(['--slug' => 'dup', '--name' => 'First']);

        $second = new CreateTenantCommand();
        $this->bind($second);
        $tester = new CommandTester($second);
        $tester->execute(['--slug' => 'dup', '--name' => 'Second']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        // Still exactly one row with that slug.
        self::assertSame(1, $this->connection()->table('tenants')->where('slug', 'dup')->count());
    }

    public function testListShowsSeededTenant(): void
    {
        $this->makeActiveTenant('globex', 'Globex Corp');

        $command = new ListTenantsCommand();
        $this->bind($command);

        $tester = new CommandTester($command);
        $exit = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exit);
        $display = $tester->getDisplay();
        self::assertStringContainsString('globex', $display);
        self::assertStringContainsString('active', $display);
    }

    public function testListEmpty(): void
    {
        $command = new ListTenantsCommand();
        $this->bind($command);

        $tester = new CommandTester($command);
        $tester->execute([]);

        self::assertStringContainsString('No tenants', $tester->getDisplay());
    }

    public function testActivateFlipsStatus(): void
    {
        $tenant = $this->makeActiveTenant('initech');
        // Flip to suspended directly so activate has something to do.
        $this->connection()->table('tenants')->where('uuid', $tenant->uuid)->update(['status' => 'suspended']);

        $command = new ActivateTenantCommand();
        $this->bind($command);

        $tester = new CommandTester($command);
        $exit = $tester->execute(['slug' => 'initech']);

        self::assertSame(Command::SUCCESS, $exit);
        $row = $this->connection()->table('tenants')->where('slug', 'initech')->first();
        self::assertSame('active', $row['status']);
    }

    public function testActivateUnknownSlugFails(): void
    {
        $command = new ActivateTenantCommand();
        $this->bind($command);

        $tester = new CommandTester($command);
        $tester->execute(['slug' => 'ghost']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
    }

    public function testSuspendFlipsStatus(): void
    {
        $this->makeActiveTenant('umbrella');

        $command = new SuspendTenantCommand();
        $this->bind($command);

        $tester = new CommandTester($command);
        $exit = $tester->execute(['slug' => 'umbrella']);

        self::assertSame(Command::SUCCESS, $exit);
        $row = $this->connection()->table('tenants')->where('slug', 'umbrella')->first();
        self::assertSame('suspended', $row['status']);
    }

    public function testDiagnoseFlagsDriftAndOrphanAndListsTables(): void
    {
        // A real registered tenant-owned table that HAS tenant_uuid.
        TenantTableRegistry::register('tenant_memberships');

        // A registered tenant-owned table that LACKS tenant_uuid → schema drift.
        TenantTableRegistry::register('drifty');
        $schema = $this->connection()->getSchemaBuilder();
        $schema->createTable('drifty', function ($table) {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('name', 64);
        });

        // Seed a tenant + a valid membership, then an ORPHAN membership (tenant_uuid with no tenant).
        $tenant = $this->makeActiveTenant('valid-tenant');
        $this->connection()->table('tenant_memberships')->insert([
            'uuid' => Utils::generateNanoID(12),
            'tenant_uuid' => $tenant->uuid,
            'user_uuid' => Utils::generateNanoID(12),
            'role' => 'member',
            'status' => 'active',
        ]);
        $this->connection()->table('tenant_memberships')->insert([
            'uuid' => Utils::generateNanoID(12),
            'tenant_uuid' => 'orphanuuid01',
            'user_uuid' => Utils::generateNanoID(12),
            'role' => 'member',
            'status' => 'active',
        ]);

        $command = new DiagnoseTenancyCommand();
        $this->bind($command);

        $tester = new CommandTester($command);
        $exit = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exit);
        $display = $tester->getDisplay();

        // (b) lists registered tenant-owned tables
        self::assertStringContainsString('tenant_memberships', $display);
        self::assertStringContainsString('drifty', $display);

        // (a) drift flagged for drifty (missing tenant_uuid)
        self::assertMatchesRegularExpression('/drifty.*(drift|tenant_uuid)/is', $display);

        // (c) membership integrity — exactly one orphan reported
        self::assertMatchesRegularExpression('/orphan/i', $display);
        self::assertStringContainsString('1', $display);
    }
}
