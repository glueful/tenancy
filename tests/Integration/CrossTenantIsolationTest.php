<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Tests\Integration;

use Glueful\Auth\UserIdentity;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Database\Execution\QueryExecutor;
use Glueful\Events\QueueContextHolder;
use Glueful\Extensions\Tenancy\Authorization\TenantAccess;
use Glueful\Extensions\Tenancy\Bypass\Tenancy;
use Glueful\Extensions\Tenancy\Context\CurrentContext;
use Glueful\Extensions\Tenancy\Context\TenantContext;
use Glueful\Extensions\Tenancy\Exceptions\MissingTenantContextException;
use Glueful\Extensions\Tenancy\Exceptions\TenantAccessDeniedException;
use Glueful\Extensions\Tenancy\Exceptions\TenantNotFoundException;
use Glueful\Extensions\Tenancy\Exceptions\TenantScopeViolationException;
use Glueful\Extensions\Tenancy\Models\Tenant;
use Glueful\Extensions\Tenancy\Queue\PropagatesTenant;
use Glueful\Extensions\Tenancy\Query\TenantQueryGuard;
use Glueful\Extensions\Tenancy\Query\TenantTableRegistry;
use Glueful\Extensions\Tenancy\Resolution\ResolverChain;
use Glueful\Extensions\Tenancy\Resolution\TenantResolutionPipeline;
use Glueful\Extensions\Tenancy\Resolution\TenantResolverInterface;
use Glueful\Extensions\Tenancy\Tests\Support\FillableProject;
use Glueful\Extensions\Tenancy\Tests\Support\Project;
use Glueful\Extensions\Tenancy\Tests\Support\TenancyTestCase;
use Glueful\Helpers\Utils;
use Glueful\Permissions\Context as GateContext;
use Glueful\Permissions\Gate;
use Glueful\Permissions\Vote;
use Glueful\Permissions\VoterInterface;
use Glueful\Queue\Job;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Task 9.1 — the cross-tenant isolation ACCEPTANCE SUITE.
 *
 * This is the security contract of the package: it proves that, acting as tenant A,
 * code can never read, write, create-into, or otherwise touch tenant B's data — across
 * every enforcement surface (scoped ORM reads/writes, create-stamping + immutability,
 * the raw-SQL query guard, queue propagation, the permission-gated bypass, and the
 * resolution pipeline).
 *
 * METHODOLOGY — every "A cannot see/touch B" assertion is PAIRED with a proof that the
 * B data genuinely exists and is reachable when scoping is INTENTIONALLY bypassed (via
 * {@see Tenancy::runAsSystem()} / {@see Tenancy::forAnyTenant()}). An isolation assertion
 * that passes only because the row never existed is worthless; the bypassed read guards
 * against that false-green.
 *
 * Two tenant-state surfaces are in play and are kept consistent here:
 *  - ORM models (Project::*) read the active tenant/bypass from their OWN context
 *    (requestState on appContext()), set via {@see TenantContext}.
 *  - The {@see Tenancy} facade and the DB-layer hooks/guard read via {@see CurrentContext}.
 * Both resolve to the SAME appContext() object in this harness, so setting tenant state
 * through either is observed by all surfaces.
 */
final class CrossTenantIsolationTest extends TenancyTestCase
{
    private Tenant $tenantA;
    private Tenant $tenantB;
    private string $uA;
    private string $uB;
    /** @var array<int, string> B's project uuids. */
    private array $bUuids = [];
    private string $bUuid;
    private int $bProjectId;

    protected function setUp(): void
    {
        parent::setUp();

        // Tenant-owned fixture tables (same shape the BelongsToTenant tests use).
        $this->connection()->getSchemaBuilder()->createTable('projects', function ($table) {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('uuid', 12);
            $table->string('tenant_uuid', 12);
            $table->string('name', 255);
            $table->index('tenant_uuid');
        });
        $this->connection()->getSchemaBuilder()->createTable('fillable_projects', function ($table) {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('uuid', 12);
            $table->string('tenant_uuid', 12);
            $table->string('name', 255);
            $table->index('tenant_uuid');
        });

        // Seed everything under a deliberate bypass so the seeding itself is never
        // tenant-scoped (and so we PROVE both tenants' rows truly exist).
        $ctx = $this->appContext();
        CurrentContext::set($ctx);

        Tenancy::runAsSystem(function (): void {
            $this->tenantA = $this->makeActiveTenant('alpha');
            $this->tenantB = $this->makeActiveTenant('beta');

            $this->uA = Utils::generateNanoID(12);
            $this->uB = Utils::generateNanoID(12);
            $this->makeMembership($this->tenantA->uuid, $this->uA, 'member', 'active');
            $this->makeMembership($this->tenantB->uuid, $this->uB, 'member', 'active');

            // 2 projects each, owned via raw inserts so seeding bypasses stamping entirely.
            for ($i = 1; $i <= 2; $i++) {
                $this->connection()->table('projects')->insert([
                    'uuid' => Utils::generateNanoID(12),
                    'tenant_uuid' => $this->tenantA->uuid,
                    'name' => "A-project-{$i}",
                ]);

                $bu = Utils::generateNanoID(12);
                $this->connection()->table('projects')->insert([
                    'uuid' => $bu,
                    'tenant_uuid' => $this->tenantB->uuid,
                    'name' => "B-project-{$i}",
                ]);
                $this->bUuids[] = $bu;
            }
        });

        $this->bUuid = $this->bUuids[0];
        // insert() returns affected-row count, not the last id — read B's pk back.
        $this->bProjectId = (int) $this->connection()->table('projects')
            ->where('uuid', $this->bUuid)->first()['id'];

        // Leave the request with NO tenant/bypass and the current-context pointer cleared;
        // each test sets exactly the state it needs.
        $this->tenancy()->clear();
        CurrentContext::clear();
    }

    protected function tearDown(): void
    {
        // Defensively unwind every process-global surface so no test leaks into the next.
        Connection::clearTableHooks();
        QueryExecutor::clearQueryInterceptors();
        QueueContextHolder::reset();
        TenantTableRegistry::clear();
        $this->tenancy()->clear();
        CurrentContext::clear();
        parent::tearDown();
    }

    private function tenancy(): TenantContext
    {
        return new TenantContext($this->appContext());
    }

    /**
     * Enter tenant A: set A as the active tenant AND point CurrentContext at the context,
     * with no bypass. This is exactly the state the `tenant` middleware leaves on a
     * member request.
     */
    private function actAsTenantA(): void
    {
        $ctx = $this->appContext();
        CurrentContext::set($ctx);
        $this->tenancy()->setTenant($this->tenantA);
    }

    // ---------------------------------------------------------------------------------
    // 1. Scoped reads are isolated to the acting tenant — paired with a bypass reachability proof.
    // ---------------------------------------------------------------------------------

    public function testScopedReadsReturnOnlyActingTenantRows(): void
    {
        $this->actAsTenantA();
        $ctx = $this->appContext();

        $rows = Project::query($ctx)->get();

        // Exactly A's two rows, every one stamped with A.
        self::assertCount(2, $rows);
        foreach ($rows as $row) {
            self::assertSame($this->tenantA->uuid, $row->tenant_uuid);
        }

        // A direct lookup of B's row returns nothing while scoped to A.
        self::assertNull(Project::find($ctx, $this->bProjectId));

        // MECHANISM PROOF: B's row is NOT missing — it is real and reachable the instant
        // scoping is bypassed. If this returned null, the isolation assertion above would
        // be a false-green. (Locals captured because runAsSystem runs a static closure.)
        $bId = $this->bProjectId;
        $bRow = Tenancy::runAsSystem(static fn () => Project::find($ctx, $bId));
        self::assertNotNull($bRow);
        self::assertSame($this->tenantB->uuid, $bRow->tenant_uuid);
        self::assertSame($this->bUuid, $bRow->uuid);
    }

    // ---------------------------------------------------------------------------------
    // 2. Scoped writes cannot reach B — update/delete affect 0 rows; B intact under bypass.
    // ---------------------------------------------------------------------------------

    /**
     * A scoped UPDATE targeting B's row while acting as A must never modify it. The
     * TenantScope ANDs in `tenant_uuid = A`, so B's row is outside the scope and the
     * statement affects exactly 0 rows (it executes cleanly — no exception).
     */
    public function testScopedUpdateCannotTouchOtherTenantRow(): void
    {
        $this->actAsTenantA();
        $ctx = $this->appContext();

        $affected = Project::query($ctx)->where('uuid', $this->bUuid)->update(['name' => 'hijacked']);
        self::assertSame(0, $affected, 'a scoped update must affect 0 of another tenant\'s rows');

        // MECHANISM PROOF: B's row still exists and is untouched (reachable under bypass).
        $bId = $this->bProjectId;
        $bRow = Tenancy::runAsSystem(static fn () => Project::find($ctx, $bId));
        self::assertNotNull($bRow);
        self::assertSame('B-project-1', $bRow->name);
        self::assertNotSame('hijacked', $bRow->name);
    }

    /**
     * Sibling of the update case: a scoped DELETE targeting B's row while acting as A must
     * never remove it — it affects 0 rows. We assert the invariant: B's row survives.
     */
    public function testScopedDeleteCannotTouchOtherTenantRow(): void
    {
        $this->actAsTenantA();
        $ctx = $this->appContext();

        $affected = Project::query($ctx)->where('uuid', $this->bUuid)->delete();
        self::assertSame(0, $affected, 'a scoped delete must remove 0 of another tenant\'s rows');

        // MECHANISM PROOF: B's row survives — still reachable under bypass.
        $bId = $this->bProjectId;
        $bRow = Tenancy::runAsSystem(static fn () => Project::find($ctx, $bId));
        self::assertNotNull($bRow);
        self::assertSame($this->tenantB->uuid, $bRow->tenant_uuid);
    }

    /**
     * Regression guard for the unqualified-column fix: a LEGITIMATE same-tenant bulk
     * update() and delete() through the scoped builder must SUCCEED. (A table-qualified
     * predicate used to throw on the framework's UPDATE/DELETE column validator, which
     * would have broken the owning tenant's own bulk writes — not just cross-tenant ones.)
     */
    public function testSameTenantBulkUpdateAndDeleteSucceed(): void
    {
        $this->actAsTenantA();
        $ctx = $this->appContext();

        // Capture one of A's own rows.
        $aRow = Project::query($ctx)->get()->first();
        self::assertNotNull($aRow);
        $aUuid = $aRow->uuid;

        // Bulk UPDATE of A's own row succeeds and affects exactly 1 row.
        $updated = Project::query($ctx)->where('uuid', $aUuid)->update(['name' => 'renamed-by-owner']);
        self::assertSame(1, $updated, 'the owning tenant must be able to bulk-update its own rows');
        self::assertSame('renamed-by-owner', Project::query($ctx)->where('uuid', $aUuid)->first()->name);

        // Bulk DELETE of A's own row succeeds and removes exactly 1 row.
        $deleted = Project::query($ctx)->where('uuid', $aUuid)->delete();
        self::assertSame(1, $deleted, 'the owning tenant must be able to bulk-delete its own rows');
        self::assertNull(Project::query($ctx)->where('uuid', $aUuid)->first());
    }

    // ---------------------------------------------------------------------------------
    // 3. Create stamps the acting tenant (even when B's uuid is force-supplied); tenant_uuid is immutable.
    // ---------------------------------------------------------------------------------

    public function testCreateStampsActingTenantAndIgnoresSuppliedForeignTenant(): void
    {
        $this->actAsTenantA();
        $ctx = $this->appContext();

        // FillableProject deliberately lists tenant_uuid in $fillable — an attacker payload
        // carrying B's uuid must still be force-stamped to A.
        $created = FillableProject::create($ctx, [
            'uuid' => Utils::generateNanoID(12),
            'tenant_uuid' => $this->tenantB->uuid, // attacker-supplied victim tenant
            'name' => 'planted',
        ]);

        self::assertSame($this->tenantA->uuid, $created->tenant_uuid);

        // Persisted row belongs to A, never to the supplied B (verified under bypass).
        $createdId = $created->id;
        $row = Tenancy::runAsSystem(static fn () => FillableProject::find($ctx, $createdId));
        self::assertNotNull($row);
        self::assertSame($this->tenantA->uuid, $row->tenant_uuid);
        self::assertNotSame($this->tenantB->uuid, $row->tenant_uuid);
    }

    public function testTenantUuidIsImmutableOnUpdate(): void
    {
        $this->actAsTenantA();
        $ctx = $this->appContext();

        $project = Project::query($ctx)->where('tenant_uuid', $this->tenantA->uuid)->first();
        self::assertNotNull($project);

        // Attempt to reassign A's row to B via the model — the updating hook rejects it.
        $project->tenant_uuid = $this->tenantB->uuid;

        $this->expectException(MissingTenantContextException::class);
        $project->save();
    }

    // ---------------------------------------------------------------------------------
    // 4. Raw unscoped access through the executor is blocked by the query guard.
    // ---------------------------------------------------------------------------------

    public function testRawUnscopedQueryAgainstTenantTableIsBlocked(): void
    {
        // Register the tenant-owned table and wire the guard into the executor seam.
        TenantTableRegistry::register('projects');
        QueryExecutor::addQueryInterceptor(new TenantQueryGuard());

        $this->actAsTenantA();

        // The harness runs as 'testing' (a dev env), so the guard's action is THROW.
        self::assertSame('testing', $this->appContext()->getEnvironment());

        $this->expectException(TenantScopeViolationException::class);

        // RAW-SQL PATH: query()->from('projects') returns a bare builder; from() does NOT
        // run Connection::$tableHooks (only table() does), so NO tenant_uuid predicate is
        // injected. The compiled "SELECT * FROM \"projects\"" reaches
        // QueryExecutor::executeStatement() unscoped and the guard's before() throws.
        $this->connection()->query()
            ->from('projects')
            ->get();
    }

    // ---------------------------------------------------------------------------------
    // 5. Queue jobs propagate the acting tenant across the worker boundary.
    // ---------------------------------------------------------------------------------

    public function testJobRestoresActingTenantOnWorker(): void
    {
        $ctx = $this->appContext();

        // Capture while acting as A.
        $this->actAsTenantA();
        $job = new IsolationRecordingJob(['x' => 1], $ctx);

        // The captured tenant uuid rides in payload (the only serialization-surviving surface).
        self::assertSame($this->tenantA->uuid, $job->getPayload()['tenant_uuid'] ?? null);

        // Simulate the worker: a fresh reused context with NO tenant set, current-context cleared.
        $workerCtx = new ApplicationContext(basePath: sys_get_temp_dir(), environment: 'testing');
        $workerCtx->setContainer($this->appContext()->getContainer());
        QueueContextHolder::setContext($workerCtx);
        CurrentContext::clear();

        $job->handle();

        // Inside handle(), tenant A was restored on the worker context.
        self::assertTrue($job->ran);
        self::assertSame($this->tenantA->uuid, $job->seenTenantUuid);

        // After handle(), the reused worker context is reset clean (ready for the next job).
        self::assertNull((new TenantContext($workerCtx))->currentTenant());
        self::assertNull((new TenantContext($workerCtx))->bypassMode());
        self::assertNull(CurrentContext::get());
    }

    // ---------------------------------------------------------------------------------
    // 6. Bypass is permission-gated: granted user sees ALL tenants; denied user is refused.
    // ---------------------------------------------------------------------------------

    public function testGrantedUserCanReadAcrossTenantsViaForAnyTenant(): void
    {
        // Wrap the harness container so it ALSO resolves the framework Gate, then put it
        // back on the SAME appContext() (so models + facade keep one context object).
        $ctx = $this->appContext();
        $ctx->setContainer($this->containerWithGate($this->grantingGateFor($this->uA)));

        // The bypass check reads the user uuid from request state (stashed by the middleware).
        $ctx->setRequestState('tenancy.user_uuid', $this->uA);
        CurrentContext::set($ctx);

        $rows = Tenancy::forAnyTenant(static fn () => Project::query($ctx)->get());

        // Both tenants' rows are visible: 2 (A) + 2 (B).
        self::assertCount(4, $rows);
        $tenantUuids = array_unique(array_map(static fn ($r) => $r->tenant_uuid, $rows->all()));
        sort($tenantUuids);
        $expected = [$this->tenantA->uuid, $this->tenantB->uuid];
        sort($expected);
        self::assertSame($expected, $tenantUuids);
    }

    public function testDeniedUserIsRefusedAndClosureNeverRuns(): void
    {
        $ctx = $this->appContext();
        // Gate grants only to uA; act as uB (a non-privileged principal w.r.t. bypass).
        $ctx->setContainer($this->containerWithGate($this->grantingGateFor($this->uA)));
        $ctx->setRequestState('tenancy.user_uuid', $this->uB);
        CurrentContext::set($ctx);

        $ran = false;

        try {
            Tenancy::forAnyTenant(static function () use (&$ran) {
                $ran = true;
                return null;
            });
            self::fail('expected TenantAccessDeniedException');
        } catch (TenantAccessDeniedException $e) {
            // expected
        }

        self::assertFalse($ran, 'the cross-tenant closure must never run when bypass is denied');
    }

    // ---------------------------------------------------------------------------------
    // 7. Resolution outcomes: inactive -> 404, non-member -> 403, valid member -> success.
    // ---------------------------------------------------------------------------------

    public function testResolutionInactiveTenantThrowsNotFound(): void
    {
        $ctx = $this->appContext();

        // Flip A to inactive directly in storage (under bypass it is reachable to mutate).
        $this->connection()->table('tenants')
            ->where('uuid', $this->tenantA->uuid)
            ->update(['status' => 'suspended']);

        $pipeline = new TenantResolutionPipeline(
            $this->chainReturning('alpha'),
            $this->accessGranting(false)
        );

        $this->expectException(TenantNotFoundException::class);
        $pipeline->resolve($this->requestForUser($this->uA), $ctx, true);
    }

    public function testResolutionActiveTenantNonMemberThrowsAccessDenied(): void
    {
        $ctx = $this->appContext();

        // uB is a member of B, NOT of A — resolving A for uB must be a 403.
        $pipeline = new TenantResolutionPipeline(
            $this->chainReturning('alpha'),
            $this->accessGranting(false)
        );

        $this->expectException(TenantAccessDeniedException::class);
        $pipeline->resolve($this->requestForUser($this->uB), $ctx, true);
    }

    public function testResolutionValidMemberSucceedsAndSetsContext(): void
    {
        $ctx = $this->appContext();

        $pipeline = new TenantResolutionPipeline(
            $this->chainReturning('alpha'),
            $this->accessGranting(false)
        );
        $pipeline->resolve($this->requestForUser($this->uA), $ctx, true);

        // The resolved tenant is set on context, with no bypass.
        self::assertSame($this->tenantA->uuid, $ctx->getRequestState('tenancy.tenant')->uuid);
        self::assertNull($ctx->getRequestState('tenancy.bypass'));
    }

    // ---------------------------------------------------------------------------------
    // Resolution-pipeline helpers (mirror ResolutionPipelineTest).
    // ---------------------------------------------------------------------------------

    private function chainReturning(?string $candidate): ResolverChain
    {
        $resolver = new class ($candidate) implements TenantResolverInterface {
            public function __construct(private ?string $candidate)
            {
            }

            public function resolve(Request $request, ApplicationContext $context): ?string
            {
                return $this->candidate;
            }
        };

        return new ResolverChain([$resolver]);
    }

    private function accessGranting(bool $bypass): TenantAccess
    {
        return new class ($bypass) extends TenantAccess {
            public function __construct(private bool $bypass)
            {
            }

            public function canBypass(ApplicationContext $context, ?string $userUuid): bool
            {
                return $this->bypass;
            }
        };
    }

    private function requestForUser(?string $userUuid): Request
    {
        $request = Request::create('/');
        if ($userUuid !== null) {
            $request->attributes->set('auth.user.uuid', $userUuid);
        }

        return $request;
    }

    // ---------------------------------------------------------------------------------
    // Gate helpers (mirror TenantAccessGateTest) — a real Gate + a faithful voter.
    // ---------------------------------------------------------------------------------

    /**
     * Wrap the harness container so it additionally resolves Gate::class to $gate, while
     * delegating everything else (the 'database'/Connection bindings) to the harness.
     */
    private function containerWithGate(Gate $gate): ContainerInterface
    {
        $base = $this->appContext()->getContainer();

        return new class ($base, $gate) implements ContainerInterface {
            public function __construct(
                private ContainerInterface $base,
                private Gate $gate,
            ) {
            }

            public function get(string $id): mixed
            {
                if ($id === Gate::class) {
                    return $this->gate;
                }
                return $this->base->get($id);
            }

            public function has(string $id): bool
            {
                if ($id === Gate::class) {
                    return true;
                }
                return $this->base->has($id);
            }
        };
    }

    /**
     * A faithful Gate: grants 'tenancy.access_any' to exactly $grantedUser, abstains otherwise,
     * so TenantAccess::canBypass() exercises the genuine decide() path.
     */
    private function grantingGateFor(string $grantedUser): Gate
    {
        $gate = new Gate('affirmative', false);
        $gate->registerVoter(new class ($grantedUser) implements VoterInterface {
            public function __construct(private string $grantedUser)
            {
            }

            public function supports(string $permission, mixed $resource, GateContext $ctx): bool
            {
                return $permission === 'tenancy.access_any';
            }

            public function vote(UserIdentity $user, string $permission, mixed $resource, GateContext $ctx): Vote
            {
                return new Vote(
                    $user->uuid() === $this->grantedUser ? Vote::GRANT : Vote::ABSTAIN
                );
            }

            public function priority(): int
            {
                return 0;
            }
        });

        return $gate;
    }
}

/**
 * A PropagatesTenant job that records the tenant it observes worker-side, used by the
 * cross-tenant isolation suite. Modeled on RecordingTenantJob: the captured tenant uuid
 * lives in payload (the only serialization-surviving surface) and is restored in handle().
 */
final class IsolationRecordingJob extends Job
{
    use PropagatesTenant;

    /** Tenant uuid observed inside the running handler. */
    public ?string $seenTenantUuid = null;

    /** Whether handle() actually ran. */
    public bool $ran = false;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(array $data = [], ?ApplicationContext $context = null)
    {
        parent::__construct($data, $context);
        $this->captureTenantContext($context);
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
