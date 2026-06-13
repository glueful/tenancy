<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Queue;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Events\QueueContextHolder;
use Glueful\Extensions\Tenancy\Context\CurrentContext;
use Glueful\Extensions\Tenancy\Context\TenantContext;
use Glueful\Extensions\Tenancy\Models\Tenant;

/**
 * Opt-in per-job tenant context propagation across the queue boundary.
 *
 * WHY OPT-IN (and not automatic): the framework queue has NO per-job middleware / lifecycle
 * hook fired by the worker, and the {@see \Glueful\Queue\Job} serializer persists ONLY
 * ['class','uuid','payload','attempts','queue'] — a subclass property is LOST across
 * serialize/unserialize. So a job cannot transparently carry tenant state; it must store the
 * tenant uuid inside $this->payload (the only surviving surface) at dispatch and restore it
 * worker-side. Automatic propagation for ALL jobs would require a core queue job-middleware
 * seam, which is NOT added in v1 — this is named future work, the same way the Phase 0 DB
 * hooks were named honestly rather than hidden.
 *
 * USAGE (a job author opts in):
 *   final class SendInvoice extends Job
 *   {
 *       use PropagatesTenant;
 *
 *       public function __construct(array $data = [], ?ApplicationContext $context = null)
 *       {
 *           parent::__construct($data, $context);
 *           $this->captureTenantContext($context); // runs inside the request
 *       }
 *
 *       public function handle(): void
 *       {
 *           $this->runInTenantContext(function (): void {
 *               // tenant-scoped work — DB guard/auto-injection now see the tenant
 *           });
 *       }
 *   }
 *
 * The worker reuses a SINGLE ApplicationContext across all jobs (via {@see QueueContextHolder}),
 * so {@see runInTenantContext()} resets tenant state in a finally — that is what isolates one
 * job from the next on the reused context.
 *
 * @mixin \Glueful\Queue\Job
 * @phpstan-require-extends \Glueful\Queue\Job
 */
trait PropagatesTenant
{
    /**
     * Capture the CURRENT tenant uuid into the job's serialization-surviving payload.
     *
     * Called at construct/dispatch time (inside the request). Reads the current tenant from
     * the given context, else {@see CurrentContext::get()}, else {@see QueueContextHolder} —
     * whichever owns the active request-scoped tenancy state. When no tenant is active, stores
     * nothing, so the job runs system-scoped (no tenant) on the worker.
     */
    public function captureTenantContext(?ApplicationContext $ctx = null): void
    {
        $ctx ??= CurrentContext::get() ?? QueueContextHolder::getContext();
        if ($ctx === null) {
            return;
        }

        $uuid = (new TenantContext($ctx))->currentTenantUuid();
        if ($uuid === null) {
            return;
        }

        // $this->payload is set by Job::__construct, but guard defensively in case a job
        // captures before parent construction.
        if (!isset($this->payload) || !is_array($this->payload)) {
            $this->payload = [];
        }

        $this->payload['tenant_uuid'] = $uuid;
    }

    /**
     * Worker-side restore: run $work with the captured tenant active on the worker context.
     *
     * Reads the captured uuid from $this->payload. Resolves the worker's ApplicationContext
     * from {@see QueueContextHolder::getContext()} (fallback: a context the job already holds).
     * When a uuid is present, the tenant is loaded and RE-VALIDATED as active — a missing or
     * inactive tenant throws a clear {@see \RuntimeException} (fail fast: never silently run
     * unscoped). On success the tenant is set on the worker {@see TenantContext} AND
     * {@see CurrentContext::set()} so the DB guard/auto-injection see it; a finally clears both,
     * resetting the reused worker context for the next job.
     *
     * When NO uuid is present the job runs system-scoped: no tenant is set (and no bypass —
     * jobs are not granted cross-tenant bypass implicitly; a job needing that uses the Tenancy
     * facade explicitly). The finally still clears state so nothing leaks onto the next job.
     *
     * @template T
     * @param callable():T $work
     * @return T
     */
    public function runInTenantContext(callable $work): mixed
    {
        $workerCtx = QueueContextHolder::getContext() ?? $this->context;
        if (!$workerCtx instanceof ApplicationContext) {
            throw new \RuntimeException(
                'PropagatesTenant: no ApplicationContext available on the worker; '
                . 'QueueContextHolder must be set by the worker before running jobs.'
            );
        }

        $uuid = (isset($this->payload) && is_array($this->payload))
            ? ($this->payload['tenant_uuid'] ?? null)
            : null;

        $tenantContext = new TenantContext($workerCtx);

        if ($uuid === null) {
            // System-scoped: run with no tenant. Clear in finally to reset the reused context.
            CurrentContext::set($workerCtx);
            try {
                return $work();
            } finally {
                $tenantContext->clear();
                CurrentContext::clear();
            }
        }

        $tenant = Tenant::query($workerCtx)->where('uuid', $uuid)->first();
        if (!$tenant instanceof Tenant || !$tenant->isActive()) {
            throw new \RuntimeException(sprintf(
                'PropagatesTenant: tenant "%s" is missing or inactive; refusing to run job unscoped.',
                $uuid
            ));
        }

        $tenantContext->setTenant($tenant);
        CurrentContext::set($workerCtx);

        try {
            return $work();
        } finally {
            $tenantContext->clear();
            CurrentContext::clear();
        }
    }
}
