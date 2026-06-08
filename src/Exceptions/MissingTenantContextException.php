<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Exceptions;

/**
 * Thrown when a tenant-required operation runs without an active tenant context
 * (and without an active bypass).
 *
 * This is the fail-closed guarantee of the tenancy core: rather than ever return
 * an unscoped result set or persist an un-stamped row, the ORM scoping layer raises
 * this exception. It is also raised when code attempts to mutate the immutable
 * `tenant_uuid` of an existing tenant-owned record.
 */
class MissingTenantContextException extends \RuntimeException
{
}
