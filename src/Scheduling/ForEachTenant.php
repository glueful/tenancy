<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Scheduling;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Tenancy\Context\CurrentContext;
use Glueful\Extensions\Tenancy\Context\TenantContext;
use Glueful\Extensions\Tenancy\Models\Tenant;

/**
 * Runs a unit of scheduled work once per ACTIVE tenant.
 *
 * The framework scheduler ({@see \Glueful\Scheduler\JobScheduler}) invokes callbacks via
 * call_user_func against a SINGLE reused {@see ApplicationContext} and provides no per-tenant
 * fan-out. This helper supplies that fan-out for tenant-scoped scheduled work: it loads every
 * active tenant and, for each, sets it on the {@see TenantContext}, mirrors the context into
 * {@see CurrentContext} (so DB scoping/auto-injection observe the tenant), runs $work($tenant),
 * and ALWAYS clears tenant + bypass + current-context in a finally before the next tenant.
 *
 * That per-iteration finally is what isolates tenants on the reused scheduler context: a throw in
 * one tenant's work unwinds cleanly, is recorded in the result, and does not leak its tenant or
 * abort later tenants. Inactive / archived tenants are skipped (only status='active' rows are
 * iterated).
 *
 * USAGE (inside a scheduled callback):
 *   ForEachTenant::run($context, function (Tenant $tenant): void {
 *       // tenant-scoped maintenance for $tenant
 *   });
 */
final class ForEachTenant
{
    /**
     * Execute $work once per active tenant, isolating each iteration.
     *
     * @param callable(Tenant):void $work
     */
    public static function run(ApplicationContext $context, callable $work): ForEachTenantResult
    {
        $tenants = Tenant::query($context)->where('status', 'active')->get();

        $tenantContext = new TenantContext($context);
        $total = 0;
        $succeeded = 0;
        /** @var array<string,\Throwable> $errors */
        $errors = [];

        foreach ($tenants as $tenant) {
            if (!$tenant instanceof Tenant) {
                continue;
            }

            $total++;
            $tenantContext->setTenant($tenant);
            CurrentContext::set($context);

            try {
                $work($tenant);
                $succeeded++;
            } catch (\Throwable $e) {
                $errors[$tenant->uuid] = $e;
            } finally {
                $tenantContext->clear();
                CurrentContext::clear();
            }
        }

        return new ForEachTenantResult(
            total: $total,
            succeeded: $succeeded,
            failed: count($errors),
            errors: $errors,
        );
    }
}
