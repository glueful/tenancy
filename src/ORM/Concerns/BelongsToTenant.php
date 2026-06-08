<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\ORM\Concerns;

use Glueful\Database\ORM\Model;
use Glueful\Extensions\Tenancy\Exceptions\MissingTenantContextException;
use Glueful\Extensions\Tenancy\Models\Tenant;
use Glueful\Extensions\Tenancy\ORM\Scopes\TenantScope;
use Glueful\Extensions\Tenancy\Query\TenantTableRegistry;

/**
 * Marks a model as tenant-owned and wires up automatic tenant isolation.
 *
 * On boot the trait:
 *  - registers the {@see TenantScope} global scope (scoped reads + fail-closed reads),
 *  - records the model's table in the {@see TenantTableRegistry},
 *  - registers a `creating` hook that stamps `tenant_uuid` from the active tenant
 *    (failing closed when there is no tenant and no bypass),
 *  - registers an `updating` hook that rejects any change to `tenant_uuid` (immutable).
 *
 * The active tenant + bypass mode are always read from the model's OWN context
 * (request-scoped state), consistent with {@see TenantScope}.
 */
trait BelongsToTenant
{
    /**
     * Boot the trait for the model (called once per class via Model::bootTraits()).
     */
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope());

        TenantTableRegistry::register((new static())->getTable());

        static::creating(static function (Model $model): void {
            $current = self::resolveCurrentTenant($model);

            $existing = $model->getAttribute(TenantScope::COLUMN);

            if ($existing === null || $existing === '') {
                if ($current === null) {
                    if (self::tenantBypassed($model)) {
                        return;
                    }

                    throw new MissingTenantContextException(sprintf(
                        'Cannot create [%s]: no active tenant context to stamp %s.',
                        $model::class,
                        TenantScope::COLUMN
                    ));
                }

                $model->setAttribute(TenantScope::COLUMN, $current->uuid);
            }
        });

        static::updating(static function (Model $model): void {
            if ($model->isDirty(TenantScope::COLUMN)) {
                throw new MissingTenantContextException(sprintf(
                    'The %s of a tenant-owned [%s] is immutable and cannot be changed.',
                    TenantScope::COLUMN,
                    $model::class
                ));
            }
        });
    }

    /**
     * Initialize the trait for an instance: ensure tenant_uuid is mass-assignable so
     * stamping/hydration round-trips cleanly (mirrors initializeSoftDeletes()).
     */
    public function initializeBelongsToTenant(): void
    {
        if (!in_array(TenantScope::COLUMN, $this->fillable, true)) {
            $this->fillable[] = TenantScope::COLUMN;
        }
    }

    /**
     * The active tenant from the model's request-scoped context, or null.
     */
    private static function resolveCurrentTenant(Model $model): ?Tenant
    {
        $tenant = $model->getContext()?->getRequestState('tenancy.tenant');

        return $tenant instanceof Tenant ? $tenant : null;
    }

    /**
     * Whether a bypass mode is active in the model's context.
     */
    private static function tenantBypassed(Model $model): bool
    {
        return $model->getContext()?->getRequestState('tenancy.bypass') !== null;
    }
}
