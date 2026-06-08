<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Tests\Support;

use Glueful\Database\ORM\Model;
use Glueful\Extensions\Tenancy\ORM\Concerns\BelongsToTenant;

/**
 * Tenant-owned fixture model used by the BelongsToTenant integration tests.
 *
 * @property int    $id
 * @property string $uuid
 * @property string $tenant_uuid
 * @property string $name
 */
class Project extends Model
{
    use BelongsToTenant;

    protected string $table = 'projects';

    protected string $primaryKey = 'id';

    protected string $keyType = 'int';

    public bool $incrementing = true;

    public bool $timestamps = false;

    /** @var array<int, string> */
    protected array $fillable = [
        'uuid',
        'name',
    ];
}
