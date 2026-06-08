<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Bypass;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Tenancy\Authorization\TenantAccess;
use Glueful\Extensions\Tenancy\Context\CurrentContext;
use Glueful\Extensions\Tenancy\Context\TenantContext;
use Glueful\Extensions\Tenancy\Exceptions\TenantAccessDeniedException;
use Glueful\Extensions\Tenancy\Exceptions\TenantNotFoundException;
use Glueful\Extensions\Tenancy\Models\Tenant;
use Glueful\Extensions\Tenancy\Query\TenantTableRegistry;

/**
 * User-facing entry point for deliberately stepping outside the default per-request tenant
 * scope. This is the privilege-escalation surface of the extension — every method here either
 * SCOPES to a chosen tenant or SUSPENDS enforcement, so the names are intentionally explicit
 * (no generic withoutScope()) and each is permission- or trust-gated where it must be.
 *
 * All methods operate on the CURRENT request's context via {@see CurrentContext::get()} and
 * follow a strict save → set → try { $fn() } finally { restore } discipline over BOTH the
 * active tenant ('tenancy.tenant') and the bypass mode ('tenancy.bypass'), so nested calls and
 * thrown exceptions always unwind to the exact prior state with nothing leaked.
 *
 * The current context must be set first — by the `tenant` middleware on a request, or by a CLI
 * / job harness — otherwise these throw a clear {@see \RuntimeException}.
 */
final class Tenancy
{
    private const KEY_TENANT = 'tenancy.tenant';
    private const KEY_BYPASS = 'tenancy.bypass';

    /**
     * Run $fn acting AS a specific tenant: queries SCOPE to it and writes stamp it, with no
     * bypass active. Accepts a {@see Tenant} or a uuid/slug string (resolved + active-checked,
     * throwing {@see TenantNotFoundException} when unknown or inactive).
     *
     * Restores the prior tenant + bypass in a finally, so this composes/ nests safely.
     */
    public static function runAsTenant(Tenant|string $tenant, callable $fn): mixed
    {
        $ctx = self::requireContext();
        $resolved = $tenant instanceof Tenant ? $tenant : self::resolveTenant($ctx, $tenant);

        return self::withState(
            $ctx,
            tenant: $resolved,
            bypass: null,
            fn: $fn,
        );
    }

    /**
     * Run $fn with NO tenant and enforcement suspended (bypass mode 'system'). For trusted
     * system work — migrations, schedulers, cross-tenant maintenance — that must not be
     * tenant-scoped. Restores the prior tenant + bypass in a finally.
     */
    public static function runAsSystem(callable $fn): mixed
    {
        return self::withState(
            self::requireContext(),
            tenant: null,
            bypass: 'system',
            fn: $fn,
        );
    }

    /**
     * Run $fn as a cross-tenant READER (bypass mode 'forAnyTenant'): scoped reads are suspended
     * so rows from every tenant are visible.
     *
     * On request paths this is PERMISSION-GATED: the current user (read context-only from
     * 'tenancy.user_uuid', stashed by the `tenant` middleware) must hold a bypass permission per
     * {@see TenantAccess::canBypass()}, else {@see TenantAccessDeniedException} is thrown and
     * $fn never runs. Trusted system/CLI callers pass $requirePermission = false to skip the
     * check. Restores the prior tenant + bypass in a finally.
     */
    public static function forAnyTenant(
        callable $fn,
        bool $requirePermission = true,
        ?TenantAccess $access = null,
    ): mixed {
        $ctx = self::requireContext();

        if ($requirePermission) {
            $access ??= self::resolveAccess($ctx);
            $userUuid = self::currentUserUuid($ctx);

            if (!$access->canBypass($ctx, $userUuid)) {
                throw new TenantAccessDeniedException('Not permitted to read across tenants');
            }
        }

        return self::withState(
            $ctx,
            tenant: null,
            bypass: 'forAnyTenant',
            fn: $fn,
        );
    }

    /**
     * Register a table as tenant-scoped (delegates to the table registry). Convenience so
     * callers can reach the registry through the same facade.
     */
    public static function registerTable(string $table): void
    {
        TenantTableRegistry::register($table);
    }

    /**
     * Save both tenancy keys, set the requested state, run $fn, and ALWAYS restore the saved
     * state — even when $fn throws. This is the single nesting/exception-safe primitive the
     * public methods build on.
     */
    private static function withState(
        ApplicationContext $ctx,
        ?Tenant $tenant,
        ?string $bypass,
        callable $fn,
    ): mixed {
        $priorTenant = $ctx->getRequestState(self::KEY_TENANT);
        $priorBypass = $ctx->getRequestState(self::KEY_BYPASS);

        $ctx->setRequestState(self::KEY_TENANT, $tenant);
        $ctx->setRequestState(self::KEY_BYPASS, $bypass);

        try {
            return $fn();
        } finally {
            $ctx->setRequestState(self::KEY_TENANT, $priorTenant);
            $ctx->setRequestState(self::KEY_BYPASS, $priorBypass);
        }
    }

    /**
     * The current request context, or a clear failure if none is set.
     */
    private static function requireContext(): ApplicationContext
    {
        $ctx = CurrentContext::get();
        if ($ctx === null) {
            throw new \RuntimeException(
                'No current ApplicationContext; the tenant middleware / CLI / job harness must '
                . 'set CurrentContext before using Tenancy::*'
            );
        }

        return $ctx;
    }

    /**
     * Resolve a uuid-or-slug string to an active {@see Tenant}, mirroring the resolution
     * pipeline's dual lookup and fail-closed active check.
     */
    private static function resolveTenant(ApplicationContext $ctx, string $candidate): Tenant
    {
        $tenant = Tenant::query($ctx)->where('uuid', $candidate)->first();
        if ($tenant === null) {
            $tenant = Tenant::query($ctx)->where('slug', $candidate)->first();
        }
        if (!$tenant instanceof Tenant || !$tenant->isActive()) {
            throw new TenantNotFoundException("Unknown tenant: {$candidate}");
        }

        return $tenant;
    }

    /**
     * The current authenticated user uuid, read context-only from request state (stashed by the
     * `tenant` middleware), or null when unauthenticated.
     */
    private static function currentUserUuid(ApplicationContext $ctx): ?string
    {
        $uuid = $ctx->getRequestState('tenancy.user_uuid');

        return is_string($uuid) ? $uuid : null;
    }

    /**
     * Resolve the {@see TenantAccess} collaborator from the container, defaulting to a fresh
     * instance when it is not bound.
     */
    private static function resolveAccess(ApplicationContext $ctx): TenantAccess
    {
        if ($ctx->hasContainer()) {
            $container = $ctx->getContainer();
            if ($container->has(TenantAccess::class)) {
                $access = $container->get(TenantAccess::class);
                if ($access instanceof TenantAccess) {
                    return $access;
                }
            }
        }

        return new TenantAccess();
    }
}
