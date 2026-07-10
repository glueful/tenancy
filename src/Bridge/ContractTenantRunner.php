<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Bridge;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Contracts\Tenancy\TenantContextRunner;
use Glueful\Extensions\Tenancy\Bypass\Tenancy;
use Glueful\Extensions\Tenancy\Context\CurrentContext;
use Glueful\Extensions\Tenancy\Exceptions\TenantIterationException;
use Glueful\Extensions\Tenancy\Models\Tenant;

/**
 * Binds the neutral TenantContextRunner contract over the extension's Bypass\Tenancy.
 *
 * Bypass\Tenancy's static methods require a CurrentContext (they call requireContext()).
 * In a request the `tenant` middleware sets it; from CLI/background it is not set, so this
 * bridge ensures one exists (from the injected ApplicationContext) before delegating.
 *
 * forEachTenant is DETERMINISTIC (created_at, then name, then uuid) and FAIL-FAST — unlike
 * the extension's Scheduling\ForEachTenant (unordered, continue-on-error), which is a
 * scheduler concern; this contract intentionally does NOT reuse it.
 */
final class ContractTenantRunner implements TenantContextRunner
{
    public function __construct(private readonly ApplicationContext $context)
    {
    }

    public function runAsTenant(string $tenantUuid, callable $fn): mixed
    {
        return $this->withContext(static fn (): mixed => Tenancy::runAsTenant($tenantUuid, $fn));
    }

    public function runAsSystem(callable $fn): mixed
    {
        return $this->withContext(static fn (): mixed => Tenancy::runAsSystem($fn));
    }

    /** @param callable(string $tenantUuid): void $fn */
    public function forEachTenant(callable $fn): void
    {
        $tenants = Tenant::query($this->context)
            ->where('status', 'active')
            ->orderBy('created_at', 'asc')
            ->orderBy('name', 'asc')
            ->orderBy('uuid', 'asc')
            ->get();

        foreach ($tenants as $tenant) {
            try {
                $this->runAsTenant($tenant->uuid, static function () use ($fn, $tenant): void {
                    $fn($tenant->uuid);
                });
            } catch (\Throwable $e) {
                throw new TenantIterationException($tenant->uuid, $e);
            }
        }
    }

    /**
     * Ensure a CurrentContext exists for Bypass\Tenancy::requireContext(). If one is already
     * active (a live request), reuse it untouched; otherwise set + clear around $fn.
     */
    private function withContext(callable $fn): mixed
    {
        if (CurrentContext::get() !== null) {
            return $fn();
        }

        CurrentContext::set($this->context);
        try {
            return $fn();
        } finally {
            CurrentContext::clear();
        }
    }
}
