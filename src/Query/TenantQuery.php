<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Query;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\QueryBuilder;
use Glueful\Extensions\Tenancy\Context\TenantContext;

/**
 * Helpers for tenant-scoped RAW query-builder access (no ORM model involved).
 *
 * {@see tenantTable()} is the safe entry point for hand-rolled query-builder code against a
 * tenant-owned table: it asserts the table is registered, then returns the auto-scoped
 * builder (the Connection table hook has already applied the `tenant_uuid = <current>`
 * predicate). {@see scope()} applies the same predicate to a builder you already hold.
 *
 * Both are no-ops under an explicit bypass mode or when there is no current tenant — the
 * fail-closed decision in those cases is the table hook's / the Phase-6 guard's job, not
 * this convenience layer's.
 */
final class TenantQuery
{
    /**
     * Return an auto-scoped query builder for a tenant-owned table.
     *
     * The {@see \Glueful\Database\Connection} table hook applies the current-tenant predicate,
     * so the returned builder is already scoped. Throws if the table is not registered as
     * tenant-owned — callers must not reach for raw access to a non-tenant table here.
     *
     * @throws \InvalidArgumentException when $name is not a registered tenant-owned table
     */
    public static function tenantTable(ApplicationContext $context, string $name): QueryBuilder
    {
        if (!TenantTableRegistry::isTenantOwned($name)) {
            throw new \InvalidArgumentException(sprintf(
                'Table [%s] is not registered as tenant-owned; refusing tenant-scoped raw access.',
                $name
            ));
        }

        return \db($context)->table($name);
    }

    /**
     * Apply the current tenant predicate to an existing builder.
     *
     * Reads the active tenant from $context's request-scoped state. No-op under an explicit
     * bypass mode or when no tenant is active (callers in those states get an unscoped builder
     * back — the broader enforcement layers decide whether that is allowed).
     */
    public static function scope(QueryBuilder $qb, ApplicationContext $context): QueryBuilder
    {
        $tenancy = new TenantContext($context);

        if ($tenancy->bypassMode() !== null) {
            return $qb;
        }

        $tenant = $tenancy->currentTenant();

        if ($tenant === null) {
            return $qb;
        }

        return $qb->where('tenant_uuid', $tenant->uuid);
    }
}
