<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Models;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\ORM\Builder;
use Glueful\Database\ORM\Model;

/**
 * Tenant — the central (global) registry record for a single tenant.
 *
 * This is a CENTRAL model: it represents the tenant directory itself and is therefore
 * NEVER tenant-scoped. It deliberately uses no BelongsToTenant / tenant-scoping trait —
 * querying tenants returns every row regardless of the active tenant context.
 *
 * Storage convention (see CreateTenantsTable): an auto-incrementing bigint `id` primary
 * key with a separate, unique 12-char `uuid` used as the stable public principal id, plus
 * a unique `slug`, a `status` ('active' by default) and a nullable JSON `settings` blob.
 *
 * @property int         $id
 * @property string      $uuid
 * @property string      $slug
 * @property string      $name
 * @property string      $status
 * @property string|null $settings
 */
class Tenant extends Model
{
    protected string $table = 'tenants';

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
        'slug',
        'name',
        'status',
        'settings',
    ];

    /**
     * Resolve a tenant by its unique slug, or null when none matches.
     */
    public static function findBySlug(ApplicationContext $context, string $slug): ?self
    {
        /** @var self|null $tenant */
        $tenant = static::query($context)->where('slug', $slug)->first();

        return $tenant;
    }

    /**
     * Whether this tenant is currently active.
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Local scope: only active tenants. Usable as ->active() on the query builder.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }
}
