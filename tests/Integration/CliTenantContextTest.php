<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Tests\Integration;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Tenancy\Console\Concerns\RunsInTenantContext;
use Glueful\Extensions\Tenancy\Context\CurrentContext;
use Glueful\Extensions\Tenancy\Context\TenantContext;
use Glueful\Extensions\Tenancy\Exceptions\TenantNotFoundException;
use Glueful\Extensions\Tenancy\Models\Tenant;
use Glueful\Extensions\Tenancy\Scheduling\ForEachTenant;
use Glueful\Extensions\Tenancy\Tests\Support\TenancyTestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * Host that exercises RunsInTenantContext directly (no full Symfony console boot needed).
 *
 * Mirrors a BaseCommand: holds an ApplicationContext exposed via getContext(), declares the
 * --tenant option through configureTenantOption(), and runs work via runInTenantContext().
 */
final class TenantAwareCommandHost
{
    use RunsInTenantContext;

    public function __construct(private readonly ApplicationContext $context)
    {
    }

    protected function getContext(): ApplicationContext
    {
        return $this->context;
    }

    /** Build a real InputInterface carrying (or omitting) --tenant. */
    public function makeInput(?string $tenant): InputInterface
    {
        $definition = new InputDefinition();
        $this->defineTenantOptionOn($definition);

        $params = $tenant !== null ? ['--tenant' => $tenant] : [];

        return new ArrayInput($params, $definition);
    }

    /** Test seam: register --tenant on a definition the same way configureTenantOption() does. */
    private function defineTenantOptionOn(InputDefinition $definition): void
    {
        $definition->addOption(new InputOption(
            'tenant',
            null,
            InputOption::VALUE_REQUIRED,
            'Tenant UUID or slug to run this command for'
        ));
    }

    /** @return mixed */
    public function run(InputInterface $input, callable $work): mixed
    {
        return $this->runInTenantContext($input, $work);
    }
}

/**
 * CLI --tenant context concern + ForEachTenant scheduler helper.
 *
 * CLI is trusted code (not request input): WITH --tenant we scope to that active tenant; WITHOUT
 * it we run in SYSTEM context (bypass 'system') so admin commands can reach tenant-scoped tables
 * across tenants. ForEachTenant iterates only ACTIVE tenants, isolating each iteration and
 * resetting the reused scheduler context between tenants.
 */
final class CliTenantContextTest extends TenancyTestCase
{
    protected function tearDown(): void
    {
        CurrentContext::clear();
        parent::tearDown();
    }

    public function test_with_tenant_option_scopes_to_that_tenant(): void
    {
        $ctx = $this->appContext();
        $acme = $this->makeActiveTenant('acme');
        $host = new TenantAwareCommandHost($ctx);

        $seen = null;
        $bypass = 'unset';
        $host->run($host->makeInput($acme->uuid), function () use ($ctx, &$seen, &$bypass): void {
            $tc = new TenantContext($ctx);
            $seen = $tc->currentTenantUuid();
            $bypass = $tc->bypassMode();
            // DB guard wiring: current context points at this command's context.
            \PHPUnit\Framework\Assert::assertSame($ctx, CurrentContext::get());
        });

        $this->assertSame($acme->uuid, $seen);
        $this->assertNull($bypass, 'scoped run is not a bypass');

        // Cleaned up afterwards.
        $tc = new TenantContext($ctx);
        $this->assertNull($tc->currentTenant());
        $this->assertNull($tc->bypassMode());
        $this->assertNull(CurrentContext::get());
    }

    public function test_with_tenant_slug_resolves(): void
    {
        $ctx = $this->appContext();
        $this->makeActiveTenant('globex');
        $host = new TenantAwareCommandHost($ctx);

        $seen = null;
        $host->run($host->makeInput('globex'), function () use ($ctx, &$seen): void {
            $seen = (new TenantContext($ctx))->currentTenant()?->slug;
        });

        $this->assertSame('globex', $seen);
    }

    public function test_without_tenant_option_runs_in_system_context(): void
    {
        $ctx = $this->appContext();
        $host = new TenantAwareCommandHost($ctx);

        $seenTenant = 'unset';
        $bypass = null;
        $host->run($host->makeInput(null), function () use ($ctx, &$seenTenant, &$bypass): void {
            $tc = new TenantContext($ctx);
            $seenTenant = $tc->currentTenant();
            $bypass = $tc->bypassMode();
            \PHPUnit\Framework\Assert::assertSame($ctx, CurrentContext::get());
        });

        $this->assertNull($seenTenant, 'no tenant in system context');
        $this->assertSame('system', $bypass, 'untargeted CLI runs as trusted system');

        // Cleaned up afterwards.
        $tc = new TenantContext($ctx);
        $this->assertNull($tc->bypassMode());
        $this->assertNull(CurrentContext::get());
    }

    public function test_unknown_tenant_option_throws(): void
    {
        $ctx = $this->appContext();
        $host = new TenantAwareCommandHost($ctx);

        $this->expectException(TenantNotFoundException::class);
        try {
            $host->run($host->makeInput('does-not-exist'), static fn () => null);
        } finally {
            // No leaked state on failure.
            $this->assertNull((new TenantContext($ctx))->currentTenant());
            $this->assertNull(CurrentContext::get());
        }
    }

    public function test_inactive_tenant_option_throws(): void
    {
        $ctx = $this->appContext();
        $acme = $this->makeActiveTenant('acme');
        $this->connection()->table('tenants')
            ->where('uuid', $acme->uuid)
            ->update(['status' => 'suspended']);
        $host = new TenantAwareCommandHost($ctx);

        $this->expectException(TenantNotFoundException::class);
        $host->run($host->makeInput($acme->uuid), static fn () => null);
    }

    public function test_for_each_tenant_iterates_active_only_and_isolates(): void
    {
        $ctx = $this->appContext();
        $acme = $this->makeActiveTenant('acme');
        $globex = $this->makeActiveTenant('globex');
        $archived = $this->makeActiveTenant('initech');
        $this->connection()->table('tenants')
            ->where('uuid', $archived->uuid)
            ->update(['status' => 'archived']);

        $seen = [];
        ForEachTenant::run($ctx, function (Tenant $tenant) use ($ctx, &$seen): void {
            // Each iteration sees exactly its own tenant scoped + the shared context wired.
            $tc = new TenantContext($ctx);
            \PHPUnit\Framework\Assert::assertSame($tenant->uuid, $tc->currentTenantUuid());
            \PHPUnit\Framework\Assert::assertSame($ctx, CurrentContext::get());
            $seen[] = $tenant->uuid;
        });

        sort($seen);
        $expected = [$acme->uuid, $globex->uuid];
        sort($expected);
        $this->assertSame($expected, $seen, 'exactly the 2 active tenants, archived skipped');
        $this->assertCount(2, array_unique($seen), 'each active tenant once');

        // Reused scheduler context reset after the loop.
        $tc = new TenantContext($ctx);
        $this->assertNull($tc->currentTenant());
        $this->assertNull($tc->bypassMode());
        $this->assertNull(CurrentContext::get());
    }

    public function test_for_each_tenant_continues_after_one_tenant_fails(): void
    {
        $ctx = $this->appContext();
        $acme = $this->makeActiveTenant('acme');
        $globex = $this->makeActiveTenant('globex');

        $seen = [];
        $result = ForEachTenant::run($ctx, function (Tenant $tenant) use (&$seen, $acme): void {
            $seen[] = $tenant->uuid;
            if ($tenant->uuid === $acme->uuid) {
                throw new \RuntimeException('tenant failed');
            }
        });

        sort($seen);
        $expected = [$acme->uuid, $globex->uuid];
        sort($expected);

        $this->assertSame($expected, $seen);
        $this->assertSame(2, $result->total);
        $this->assertSame(1, $result->succeeded);
        $this->assertSame(1, $result->failed);
        $this->assertArrayHasKey($acme->uuid, $result->errors);
        $this->assertNull((new TenantContext($ctx))->currentTenant());
        $this->assertNull(CurrentContext::get());
    }
}
