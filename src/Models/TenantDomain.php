<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Models;

use Glueful\Database\ORM\Model;

/** Central host-to-tenant mapping; never tenant-scoped itself. */
class TenantDomain extends Model
{
    public const VERIFICATION_PENDING = 'pending';
    public const VERIFICATION_VERIFIED = 'verified';
    public const VERIFICATION_REVOKED = 'revoked';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_DISABLED = 'disabled';

    protected string $table = 'tenant_domains';
    protected string $primaryKey = 'id';
    protected string $keyType = 'int';
    public bool $incrementing = true;
    public bool $timestamps = false;

    /** @var list<string> */
    protected array $fillable = [
        'uuid',
        'tenant_uuid',
        'host',
        'verification_token',
    ];

    public function isPubliclyResolvable(): bool
    {
        return $this->verification_status === self::VERIFICATION_VERIFIED
            && $this->status === self::STATUS_ACTIVE;
    }
}
