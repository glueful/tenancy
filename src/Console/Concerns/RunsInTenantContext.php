<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Console\Concerns;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Tenancy\Context\CurrentContext;
use Glueful\Extensions\Tenancy\Context\TenantContext;
use Glueful\Extensions\Tenancy\Exceptions\TenantNotFoundException;
use Glueful\Extensions\Tenancy\Models\Tenant;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * Adds a `--tenant` switch and tenant-scoped execution to a {@see \Glueful\Console\BaseCommand}.
 *
 * Console commands run OUTSIDE the request lifecycle, so the `tenant` middleware never sets the
 * tenant for them. This concern gives a command a deliberate, trusted way to pick a tenant.
 *
 * TRUST MODEL — the no-tenant default is SYSTEM context, not "unscoped/null". A CLI invocation is
 * operator-controlled code (not untrusted request input), so an admin command run without
 * --tenant must be able to touch tenant-scoped tables across all tenants. We therefore set bypass
 * mode 'system' (no active tenant) — the same posture as {@see Tenancy::runAsSystem()}. WITH
 * --tenant we instead scope to that single (active-validated) tenant and set NO bypass.
 *
 * Both paths set {@see CurrentContext::set()} so the DB guard / auto-injection observe the chosen
 * state, and ALWAYS clear tenant + bypass + current-context in a finally so nothing leaks (e.g.
 * between commands sharing a long-lived process).
 *
 * USAGE:
 *   protected function configure(): void
 *   {
 *       $this->setName('reports:build');
 *       $this->configureTenantOption();
 *   }
 *
 *   protected function execute(InputInterface $input, OutputInterface $output): int
 *   {
 *       return $this->runInTenantContext($input, function (): int {
 *           // ... tenant-scoped (or system) work
 *           return self::SUCCESS;
 *       });
 *   }
 *
 * @phpstan-require-extends \Glueful\Console\BaseCommand
 */
trait RunsInTenantContext
{
    /**
     * Register the `--tenant` option. Call from the command's configure().
     */
    protected function configureTenantOption(): void
    {
        $this->addOption(
            'tenant',
            null,
            InputOption::VALUE_REQUIRED,
            'Tenant UUID or slug to run this command for'
        );
    }

    /**
     * Run $work with tenant context derived from the `--tenant` option.
     *
     * With --tenant: resolve+validate an ACTIVE tenant (uuid first, then slug) — missing/inactive
     * throws {@see TenantNotFoundException} and $work never runs. Without --tenant: run in trusted
     * SYSTEM context (bypass 'system', no tenant). Either way the chosen state is set on the
     * command's ApplicationContext, mirrored into {@see CurrentContext}, and cleared in a finally.
     *
     * @template T
     * @param callable():T $work
     * @return T
     */
    protected function runInTenantContext(InputInterface $input, callable $work): mixed
    {
        $ctx = $this->getContext();
        $tenantContext = new TenantContext($ctx);

        $option = $input->getOption('tenant');
        $candidate = is_string($option) && $option !== '' ? $option : null;

        if ($candidate === null) {
            // Trusted system context: no tenant, enforcement suspended.
            $tenantContext->setBypass('system');
            CurrentContext::set($ctx);

            try {
                return $work();
            } finally {
                $tenantContext->clear();
                CurrentContext::clear();
            }
        }

        $tenant = $this->resolveActiveTenant($ctx, $candidate);
        $tenantContext->setTenant($tenant);
        CurrentContext::set($ctx);

        try {
            return $work();
        } finally {
            $tenantContext->clear();
            CurrentContext::clear();
        }
    }

    /**
     * Resolve a uuid-or-slug to an ACTIVE tenant, fail-closed: unknown or inactive throws
     * {@see TenantNotFoundException} (mirrors the resolution pipeline's dual lookup).
     */
    private function resolveActiveTenant(ApplicationContext $ctx, string $candidate): Tenant
    {
        $tenant = Tenant::query($ctx)->where('uuid', $candidate)->first();
        if ($tenant === null) {
            $tenant = Tenant::query($ctx)->where('slug', $candidate)->first();
        }

        if (!$tenant instanceof Tenant || !$tenant->isActive()) {
            throw new TenantNotFoundException("Unknown or inactive tenant: {$candidate}");
        }

        return $tenant;
    }
}
