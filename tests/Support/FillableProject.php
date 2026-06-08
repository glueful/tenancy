<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Tests\Support;

use Glueful\Database\ORM\Model;
use Glueful\Extensions\Tenancy\ORM\Concerns\BelongsToTenant;

/**
 * Tenant-owned fixture model whose $fillable DELIBERATELY includes `tenant_uuid`.
 *
 * This models a (mis)configured consumer that exposes `tenant_uuid` to mass
 * assignment. It exists to prove the security invariant of BelongsToTenant: the
 * `creating` hook force-sets `tenant_uuid` from the active tenant via setAttribute,
 * so an attacker-supplied `tenant_uuid` in the create payload can NEVER plant a row
 * in a victim tenant — regardless of the consumer's mass-assignment config.
 *
 * @property int    $id
 * @property string $uuid
 * @property string $tenant_uuid
 * @property string $name
 */
class FillableProject extends Model
{
    use BelongsToTenant;

    protected string $table = 'fillable_projects';

    protected string $primaryKey = 'id';

    protected string $keyType = 'int';

    public bool $incrementing = true;

    public bool $timestamps = false;

    /** @var array<int, string> */
    protected array $fillable = [
        'uuid',
        'tenant_uuid',
        'name',
    ];
}
