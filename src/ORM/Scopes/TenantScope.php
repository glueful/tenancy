<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\ORM\Scopes;

use Glueful\Database\ORM\Builder;
use Glueful\Database\ORM\Contracts\ExtendsBuilder;
use Glueful\Database\ORM\Contracts\Scope;
use Glueful\Extensions\Tenancy\Exceptions\MissingTenantContextException;
use Glueful\Extensions\Tenancy\Models\Tenant;

/**
 * Global scope that constrains every query for a tenant-owned model to the active tenant.
 *
 * The active tenant + bypass mode are read from the MODEL'S OWN context
 * ({@see \Glueful\Database\ORM\Model::getContext()}) — never from an ambient/static global —
 * via the request-scoped state keys `tenancy.tenant` and `tenancy.bypass`.
 *
 * Behaviour:
 *  - A bypass mode is active  → no predicate (query runs across all tenants).
 *  - A tenant is active       → `where {table}.tenant_uuid = {tenant.uuid}`.
 *  - No tenant + tenant-required → THROW {@see MissingTenantContextException} (fail closed).
 *
 * It also extends the builder with the noisy bypass macros `withoutTenantScope()` and
 * `forAnyTenant()`, each of which removes THIS scope so the query runs unscoped.
 */
final class TenantScope implements Scope, ExtendsBuilder
{
    public const COLUMN = 'tenant_uuid';

    public function apply(Builder $builder, object $model): void
    {
        $ctx = $model->getContext();

        $bypass = $ctx?->getRequestState('tenancy.bypass');
        if ($bypass !== null) {
            // A bypass mode (e.g. 'forAnyTenant') is active — no tenant predicate.
            return;
        }

        $tenant = $ctx?->getRequestState('tenancy.tenant');
        if ($tenant instanceof Tenant) {
            $builder->where($model->getTable() . '.' . self::COLUMN, $tenant->uuid);
            return;
        }

        // No tenant in context. Fail closed for tenant-required models rather than
        // ever returning an unscoped result set. A null context is treated as required.
        $required = $ctx === null
            ? true
            : (bool) \config($ctx, 'tenancy.enforcement.required_by_default', true);

        if ($required) {
            throw new MissingTenantContextException(sprintf(
                'No active tenant context for tenant-scoped model [%s]. '
                . 'Set a tenant, or use withoutTenantScope()/forAnyTenant() / a bypass mode to query across tenants.',
                $model::class
            ));
        }
    }

    /**
     * Extend the builder with the tenancy-specific bypass macros.
     */
    public function extend(Builder $builder): void
    {
        $remove = function (Builder $builder): Builder {
            return $builder->withoutGlobalScope(static::class);
        };

        $builder->macro('withoutTenantScope', $remove);
        $builder->macro('forAnyTenant', $remove);
    }
}
