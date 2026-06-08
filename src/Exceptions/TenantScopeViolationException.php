<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Exceptions;

/**
 * Thrown by {@see \Glueful\Extensions\Tenancy\Query\TenantQueryGuard} when a query reaches the
 * database referencing a registered tenant-owned table WITHOUT a tenant_uuid predicate, while a
 * tenant request is in flight and no bypass is active.
 *
 * This is the pre-execution safety net: in dev/test it surfaces accidental raw/unscoped access
 * to tenant data as a failing query (and therefore a failing test) before any row is read or
 * written. It is NEVER thrown in production — there the guard only logs/metrics or no-ops.
 */
final class TenantScopeViolationException extends \RuntimeException
{
}
