<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Tests\Integration;

use Glueful\Extensions\Tenancy\Authorization\TenantAccess;
use Glueful\Extensions\Tenancy\Tests\Support\TenancyTestCase;
use Glueful\Permissions\PermissionManager;
use Glueful\Testing\InMemoryPermissionProvider;

/**
 * canBypass must consult the app's ACTIVE permission provider (e.g. an RBAC extension
 * like aegis), not just the Gate's config voters — otherwise bypass permissions granted
 * through provider-managed roles never unlock cross-tenant access. The Gate alone never
 * sees provider-backed grants (it only consults the provider when handed a providerDecide
 * callback, which the bare Gate::decide() path does not pass), and tenancy's bare
 * UserIdentity carries no roles for the default voters to match.
 */
final class TenantAccessProviderTest extends TenancyTestCase
{
    protected function tearDown(): void
    {
        // The active provider is process-global static state — reset between cases.
        PermissionManager::getInstance()->clearProvider();
        parent::tearDown();
    }

    public function test_active_provider_grant_unlocks_bypass(): void
    {
        $ctx = $this->appContext();
        PermissionManager::getInstance(null, $ctx)->setProvider(
            new InMemoryPermissionProvider(['admin-user' => ['tenancy.access_any']])
        );

        $access = new TenantAccess();

        self::assertTrue(
            $access->canBypass($ctx, 'admin-user'),
            'a user granted tenancy.access_any via the active provider must be allowed to bypass'
        );
        self::assertFalse(
            $access->canBypass($ctx, 'regular-user'),
            'a user without the permission must not bypass'
        );
        self::assertFalse($access->canBypass($ctx, null), 'a null user must never bypass');
    }

    public function test_provider_consulted_for_the_manage_permission_too(): void
    {
        $ctx = $this->appContext();
        PermissionManager::getInstance(null, $ctx)->setProvider(
            new InMemoryPermissionProvider(['mgr' => ['tenancy.manage']])
        );

        self::assertTrue((new TenantAccess())->canBypass($ctx, 'mgr'));
    }

    public function test_provider_that_grants_nothing_denies_bypass(): void
    {
        $ctx = $this->appContext();
        PermissionManager::getInstance(null, $ctx)->setProvider(
            new InMemoryPermissionProvider(['someone' => ['users.read']]) // unrelated perm
        );

        self::assertFalse((new TenantAccess())->canBypass($ctx, 'someone'));
    }
}
