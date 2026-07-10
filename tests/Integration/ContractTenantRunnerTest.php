<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Tests\Integration;

use Glueful\Extensions\Contracts\Tenancy\TenantContextRunner;
use Glueful\Extensions\Tenancy\Bridge\ContractTenantRunner;
use Glueful\Extensions\Tenancy\Context\CurrentContext;
use Glueful\Extensions\Tenancy\Context\TenantContext;
use Glueful\Extensions\Tenancy\Exceptions\TenantIterationException;
use Glueful\Extensions\Tenancy\Models\Tenant;
use Glueful\Extensions\Tenancy\Tests\Support\TenancyTestCase;

final class ContractTenantRunnerTest extends TenancyTestCase
{
    private function runner(): ContractTenantRunner
    {
        return new ContractTenantRunner($this->appContext());
    }

    protected function tearDown(): void
    {
        CurrentContext::clear();
        parent::tearDown();
    }

    /** Create an active tenant with a controlled created_at so ordering is deterministic. */
    private function tenant(string $uuid, string $slug, string $name, string $createdAt): Tenant
    {
        $t = Tenant::create($this->appContext(), ['uuid' => $uuid, 'slug' => $slug, 'name' => $name]);
        $this->connection()->table('tenants')->where('uuid', $uuid)->update(['created_at' => $createdAt]);
        return $t;
    }

    public function testImplementsContract(): void
    {
        self::assertInstanceOf(TenantContextRunner::class, $this->runner());
    }

    public function testRunAsTenantSetsCurrentTenantAndReturnsValue(): void
    {
        $t = $this->tenant('tenaaaaaaaa1', 'acme', 'Acme', '2026-01-01 00:00:00');

        $seen = $this->runner()->runAsTenant($t->uuid, static function (): ?string {
            $ctx = CurrentContext::get();
            self::assertNotNull($ctx);
            return $ctx->getRequestState('tenancy.tenant')?->uuid;
        });

        self::assertSame('tenaaaaaaaa1', $seen);
    }

    public function testForEachTenantIsDeterministicAndFailFast(): void
    {
        // Seed out of order; created_at is the primary sort key. B is created_at-earlier, so
        // it must iterate first regardless of insert order / name / uuid.
        $this->tenant('tenbbbbbbbb2', 'b', 'Beta', '2026-01-01 00:00:00');
        $this->tenant('tenaaaaaaaa1', 'a', 'Alpha', '2026-01-02 00:00:00');

        $order = [];
        $this->runner()->forEachTenant(static function (string $uuid) use (&$order): void {
            $order[] = $uuid;
        });
        self::assertSame(['tenbbbbbbbb2', 'tenaaaaaaaa1'], $order, 'ordered by created_at (then name, uuid)');

        // Fail-fast: throwing inside the callback stops iteration and surfaces the tenant uuid.
        $hit = [];
        try {
            $this->runner()->forEachTenant(static function (string $uuid) use (&$hit): void {
                $hit[] = $uuid;
                throw new \RuntimeException('boom');
            });
            self::fail('expected TenantIterationException');
        } catch (TenantIterationException $e) {
            self::assertSame('tenbbbbbbbb2', $e->tenantUuid);
            self::assertCount(1, $hit, 'stopped after the first failing tenant');
        }
    }

    public function testPriorContextIsRestoredAfterSuccessAndException(): void
    {
        $a = $this->tenant('tenaaaaaaaaA', 'a', 'A', '2026-01-01 00:00:00');
        $b = $this->tenant('tenbbbbbbbbB', 'b', 'B', '2026-01-02 00:00:00');

        // Establish an outer context pinned to tenant A (as the middleware would in a request).
        CurrentContext::set($this->appContext());
        (new TenantContext($this->appContext()))->setTenant($a);

        // Nested runAsTenant(B) on success must restore A afterwards.
        $this->runner()->runAsTenant($b->uuid, static fn (): int => 1);
        self::assertSame(
            'tenaaaaaaaaA',
            CurrentContext::get()?->getRequestState('tenancy.tenant')?->uuid,
            'A restored after success',
        );

        // Nested runAsTenant(B) that throws must ALSO restore A (no tenant-B leak).
        try {
            $this->runner()->runAsTenant($b->uuid, static function (): void {
                throw new \RuntimeException('boom');
            });
            self::fail('expected the inner throw to propagate');
        } catch (\RuntimeException) {
            // expected
        }
        self::assertSame(
            'tenaaaaaaaaA',
            CurrentContext::get()?->getRequestState('tenancy.tenant')?->uuid,
            'A restored after exception',
        );
        self::assertNull(CurrentContext::get()?->getRequestState('tenancy.bypass'), 'no bypass state leaked');
    }
}
