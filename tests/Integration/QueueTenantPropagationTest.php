<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Tests\Integration;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Events\QueueContextHolder;
use Glueful\Extensions\Tenancy\Context\CurrentContext;
use Glueful\Extensions\Tenancy\Context\TenantContext;
use Glueful\Extensions\Tenancy\Models\Tenant;
use Glueful\Extensions\Tenancy\Queue\PropagatesTenant;
use Glueful\Extensions\Tenancy\Tests\Support\TenancyTestCase;
use Glueful\Queue\Job;
use Glueful\Security\SecureSerializer;

/**
 * A job that propagates the current tenant across the queue boundary.
 *
 * The framework's {@see Job} serialization persists ONLY ['class','uuid','payload','attempts',
 * 'queue'] — a subclass property is LOST across serialize/unserialize. So the captured tenant
 * uuid must live in $this->payload (which survives) — that is exactly what PropagatesTenant does.
 */
final class RecordingTenantJob extends Job
{
    use PropagatesTenant;

    /** Tenant uuid observed inside the running handler (recorded for assertions). */
    public ?string $seenTenantUuid = null;

    /** Whether handle() actually ran. */
    public bool $ran = false;

    public function __construct(array $data = [], ?ApplicationContext $context = null)
    {
        parent::__construct($data, $context);
        // Capture happens at construct/dispatch time, inside the request.
        $this->captureTenantContext($context);
    }

    /** Test seam: overwrite the payload to mimic a worker-side reconstructed job. */
    public function setPayloadForTest(array $payload): void
    {
        $this->payload = $payload;
    }

    public function handle(): void
    {
        $this->runInTenantContext(function (): void {
            $this->ran = true;
            $workerCtx = QueueContextHolder::getContext();
            $this->seenTenantUuid = $workerCtx !== null
                ? (new TenantContext($workerCtx))->currentTenantUuid()
                : null;
        });
    }
}

/**
 * Per-job tenant context propagation (PropagatesTenant).
 *
 * The framework queue worker reuses a SINGLE ApplicationContext across all jobs (via
 * QueueContextHolder) and fires no per-job middleware, so propagation is opt-in: the job
 * carries its tenant uuid in payload (the only serialization-surviving surface) and restores
 * it worker-side, resetting that reused context between jobs in a finally.
 */
final class QueueTenantPropagationTest extends TenancyTestCase
{
    protected function tearDown(): void
    {
        QueueContextHolder::reset();
        CurrentContext::clear();
        parent::tearDown();
    }

    public function test_capture_and_restore_round_trip(): void
    {
        $ctx = $this->appContext();
        $a = $this->makeActiveTenant('acme');

        // Tenant-A request: set tenant + current context, then construct (captures A).
        (new TenantContext($ctx))->setTenant($a);
        CurrentContext::set($ctx);

        $job = new RecordingTenantJob(['x' => 1], $ctx);

        // The captured tenant uuid must live in the serialization-surviving payload (Job::serialize
        // persists ONLY ['class','uuid','payload','attempts','queue'] — payload is the surface).
        $this->assertSame($a->uuid, $job->getPayload()['tenant_uuid'] ?? null);

        // Prove it survives serialize(). NOTE: we decode the blob with a clean SecureSerializer
        // rather than Job::unserialize(): the framework's SecureSerializer::forQueue() registers a
        // wildcard "Glueful\Queue\Jobs\*" allowlist entry that PHP's native unserialize() rejects
        // ("allowed_classes must be an array of class names"), so Job::unserialize() throws for ANY
        // job class today. That is a pre-existing CORE bug, orthogonal to tenancy — we do NOT touch
        // core to fix it. The payload (plain scalars) round-trips cleanly, which is all this trait
        // relies on.
        $serialized = $job->serialize();
        $clean = new SecureSerializer([RecordingTenantJob::class], false);
        $props = $clean->unserialize($serialized, [RecordingTenantJob::class]);
        $this->assertIsArray($props);
        $this->assertSame($a->uuid, $props['payload']['tenant_uuid'] ?? null);

        // Reconstruct the worker-side job from the surviving payload (what a fixed unserialize would
        // hand back): a fresh instance carrying the same payload, no tenant in its own state.
        CurrentContext::clear();
        $restored = new RecordingTenantJob($props['payload']['data'] ?? []);
        $restored->setPayloadForTest($props['payload']);
        $this->assertSame($a->uuid, $restored->getPayload()['tenant_uuid'] ?? null);

        // Simulate the worker: fresh reused context, NO tenant set, CurrentContext cleared.
        $workerCtx = new ApplicationContext(basePath: sys_get_temp_dir(), environment: 'testing');
        $workerCtx->setContainer($this->appContext()->getContainer());
        QueueContextHolder::setContext($workerCtx);
        CurrentContext::clear();

        $restored->handle();

        // Inside the handler, tenant A was active on the worker context.
        $this->assertTrue($restored->ran);
        $this->assertSame($a->uuid, $restored->seenTenantUuid);

        // After handle(), both holders are reset on the worker context (ready for next job).
        $this->assertNull((new TenantContext($workerCtx))->currentTenant());
        $this->assertNull((new TenantContext($workerCtx))->bypassMode());
        $this->assertNull(CurrentContext::get());
    }

    public function test_inactive_tenant_fails_fast(): void
    {
        $ctx = $this->appContext();
        $a = $this->makeActiveTenant('acme');

        (new TenantContext($ctx))->setTenant($a);
        CurrentContext::set($ctx);
        $job = new RecordingTenantJob(['x' => 1], $ctx);

        // Archive the tenant after capture: worker-side re-validation must reject it. Flip the
        // status directly in storage (not Model::save(), whose SoftDelete update path hits an
        // unrelated core column-quoting quirk under this in-memory harness).
        $this->connection()->table('tenants')
            ->where('uuid', $a->uuid)
            ->update(['status' => 'archived']);

        $workerCtx = new ApplicationContext(basePath: sys_get_temp_dir(), environment: 'testing');
        $workerCtx->setContainer($this->appContext()->getContainer());
        QueueContextHolder::setContext($workerCtx);
        CurrentContext::clear();

        try {
            $job->handle();
            $this->fail('expected a RuntimeException for inactive tenant');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsStringIgnoringCase('tenant', $e->getMessage());
        }

        // Worker context left clean — no leaked tenant or current-context pointer.
        $this->assertFalse($job->ran, 'work must not run when tenant is inactive');
        $this->assertNull((new TenantContext($workerCtx))->currentTenant());
        $this->assertNull(CurrentContext::get());
    }

    public function test_no_tenant_runs_system_scoped(): void
    {
        $ctx = $this->appContext();

        // Construct OUTSIDE any tenant: nothing captured.
        CurrentContext::set($ctx);
        $job = new RecordingTenantJob(['x' => 1], $ctx);
        CurrentContext::clear();

        $this->assertArrayNotHasKey('tenant_uuid', $job->getPayload());

        $workerCtx = new ApplicationContext(basePath: sys_get_temp_dir(), environment: 'testing');
        $workerCtx->setContainer($this->appContext()->getContainer());
        QueueContextHolder::setContext($workerCtx);
        CurrentContext::clear();

        $job->handle(); // must NOT throw

        $this->assertTrue($job->ran);
        $this->assertNull($job->seenTenantUuid, 'no tenant -> system-scoped (no tenant active)');
        // Reused context reset afterwards.
        $this->assertNull((new TenantContext($workerCtx))->currentTenant());
        $this->assertNull(CurrentContext::get());
    }
}
