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
 *  - registers a `creating` hook that FORCE-SETS `tenant_uuid` from the active tenant
 *    (failing closed when there is no tenant and no bypass),
 *  - registers an `updating` hook that rejects any change to `tenant_uuid` (immutable).
 *
 * Security — `tenant_uuid` is force-set on create via {@see Model::setAttribute()},
 * which bypasses `$fillable`. The stamped value UNCONDITIONALLY overwrites any
 * caller-supplied `tenant_uuid` (e.g. from a mass-assigned request body), so a
 * consumer model can NEVER plant a row in another tenant even if it lists
 * `tenant_uuid` in `$fillable` or is `$unguarded`. Consumer models therefore do
 * NOT need `tenant_uuid` in `$fillable` for security; reads hydrate it from raw
 * attributes regardless of mass-assignment config. The only carve-out is an
 * explicit bypass mode (runAsSystem/runAsTenant/forAnyTenant), under which a
 * supplied value is honored because the caller is already privileged.
 *
 * Raw-write gap — the `updating` immutability guard runs on MODEL events only
 * (save()/update()/forceFill()->save()). A raw query-builder write such as
 * `db()->table('x')->update(['tenant_uuid' => ...])` or
 * `Model::query($ctx)->where(...)->update([...])` bypasses model events entirely,
 * so it is NOT covered by this guard. The tenant scope predicate still applies to
 * such writes (you can only touch rows already visible to the current tenant — this
 * is row-reassignment-out, not a foreign read), and the Phase-6 query guard plus
 * always using models are the enforcement for raw paths.
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
            // Privileged carve-out: under an explicit bypass mode (runAsSystem /
            // runAsTenant / forAnyTenant) the caller controls tenant_uuid — leave
            // any supplied value untouched.
            if (self::tenantBypassed($model)) {
                return;
            }

            // Normal path: resolve the active tenant or fail closed.
            $current = self::resolveCurrentTenant($model);

            if ($current === null) {
                throw new MissingTenantContextException(sprintf(
                    'Cannot create [%s]: no active tenant context to stamp %s.',
                    $model::class,
                    TenantScope::COLUMN
                ));
            }

            // FORCE the active tenant, overwriting any caller-supplied value.
            // setAttribute bypasses $fillable, so this holds regardless of the
            // consumer model's mass-assignment config — security must not depend
            // on the consumer keeping tenant_uuid out of $fillable.
            $model->setAttribute(TenantScope::COLUMN, $current->uuid);
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
