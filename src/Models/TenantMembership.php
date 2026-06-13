<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Models;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\ORM\Builder;
use Glueful\Database\ORM\Model;

/**
 * TenantMembership — the central bridge record granting a (global) user a role in a tenant.
 *
 * This is a CENTRAL model: like {@see Tenant} it is part of the registry and is NOT
 * tenant-scoped. It carries no BelongsToTenant trait. `tenant_uuid` has a hard FK to
 * tenants(uuid) (both tables owned by this package); `user_uuid` is an indexed external
 * principal id with no FK (the user store is a separate package).
 *
 * Storage convention (see CreateTenantMembershipsTable): auto-incrementing bigint `id`
 * primary key, a unique 12-char `uuid`, plus `tenant_uuid`, `user_uuid`, `role` ('member'
 * by default) and `status` ('active' by default).
 *
 * @property int    $id
 * @property string $uuid
 * @property string $tenant_uuid
 * @property string $user_uuid
 * @property string $role
 * @property string $status
 */
class TenantMembership extends Model
{
    protected string $table = 'tenant_memberships';

    protected string $primaryKey = 'id';

    protected string $keyType = 'int';

    public bool $incrementing = true;

    /**
     * created_at / updated_at are populated by the DB (CURRENT_TIMESTAMP defaults in the
     * migration), so the ORM must not manage them — it would bind DateTimeImmutable objects.
     */
    public bool $timestamps = false;

    /** @var array<int, string> */
    protected array $fillable = [
        'uuid',
        'tenant_uuid',
        'user_uuid',
    ];

    /**
     * Begin a query for all of a user's memberships (across tenants).
     *
     * Returns a Builder so callers can further constrain it, e.g.
     * TenantMembership::forUser($ctx, $uuid)->active()->get().
     */
    public static function forUser(ApplicationContext $context, string $userUuid): Builder
    {
        return static::query($context)->where('user_uuid', $userUuid);
    }

    /**
     * Local scope: only active memberships. Usable as ->active() on the query builder.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }
}
