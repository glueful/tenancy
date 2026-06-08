<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Tests\Integration;

use Glueful\Auth\UserIdentity;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Tenancy\Authorization\TenantAccess;
use Glueful\Extensions\Tenancy\Tests\Support\TenancyTestCase;
use Glueful\Permissions\Context as GateContext;
use Glueful\Permissions\Gate;
use Glueful\Permissions\Vote;
use Glueful\Permissions\VoterInterface;
use Psr\Container\ContainerInterface;

/**
 * TenantAccess::canBypass() resolves the framework authorization Gate from the container and
 * grants bypass iff the Gate decides GRANT for ANY configured bypass permission. It fails
 * CLOSED: a null user, an unprivileged user, or no Gate bound all yield false.
 */
final class TenantAccessGateTest extends TenancyTestCase
{
    public const GRANTED_USER = 'admin-uuid';
    public const OTHER_USER = 'plain-uuid';

    /**
     * Wrap the harness container so it additionally resolves Gate::class to $gate (or omits
     * it entirely when $gate is null, to model "no Gate bound").
     */
    private function contextWithGate(?Gate $gate): ApplicationContext
    {
        $base = $this->appContext()->getContainer();

        $wrapper = new class ($base, $gate) implements ContainerInterface {
            public function __construct(
                private ContainerInterface $base,
                private ?Gate $gate,
            ) {
            }

            public function get(string $id): mixed
            {
                if ($id === Gate::class) {
                    if ($this->gate === null) {
                        throw new \RuntimeException('Gate not bound');
                    }
                    return $this->gate;
                }
                return $this->base->get($id);
            }

            public function has(string $id): bool
            {
                if ($id === Gate::class) {
                    return $this->gate !== null;
                }
                return $this->base->has($id);
            }
        };

        $ctx = new ApplicationContext(basePath: sys_get_temp_dir(), environment: 'testing');
        $ctx->setContainer($wrapper);

        return $ctx;
    }

    /**
     * A faithful fake voter: grants 'tenancy.access_any' for the granted user, abstains
     * otherwise. Models a real Gate voter so canBypass exercises the genuine decide() path.
     */
    private function grantingGate(): Gate
    {
        $gate = new Gate('affirmative', false);
        $gate->registerVoter(new class implements VoterInterface {
            public function supports(string $permission, mixed $resource, GateContext $ctx): bool
            {
                return $permission === 'tenancy.access_any';
            }

            public function vote(UserIdentity $user, string $permission, mixed $resource, GateContext $ctx): Vote
            {
                return new Vote(
                    $user->uuid() === TenantAccessGateTest::GRANTED_USER ? Vote::GRANT : Vote::ABSTAIN
                );
            }

            public function priority(): int
            {
                return 0;
            }
        });

        return $gate;
    }

    public function test_granted_user_can_bypass(): void
    {
        $ctx = $this->contextWithGate($this->grantingGate());
        $access = new TenantAccess();

        $this->assertTrue($access->canBypass($ctx, self::GRANTED_USER));
    }

    public function test_other_user_cannot_bypass(): void
    {
        $ctx = $this->contextWithGate($this->grantingGate());
        $access = new TenantAccess();

        $this->assertFalse($access->canBypass($ctx, self::OTHER_USER));
    }

    public function test_null_user_cannot_bypass(): void
    {
        $ctx = $this->contextWithGate($this->grantingGate());
        $access = new TenantAccess();

        $this->assertFalse($access->canBypass($ctx, null));
    }

    public function test_no_gate_bound_fails_closed(): void
    {
        $ctx = $this->contextWithGate(null);
        $access = new TenantAccess();

        $this->assertFalse($access->canBypass($ctx, self::GRANTED_USER));
    }
}
