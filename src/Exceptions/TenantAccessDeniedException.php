<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Exceptions;

/**
 * Thrown when an authenticated principal is not a member of an existing, active tenant and
 * holds no cross-tenant bypass.
 *
 * This is only raised AFTER tenant existence/active checks pass, so it confirms the tenant
 * is real — never used for unknown tenants (those are 404). Mapped to HTTP 403 by the
 * tenant-resolution middleware (Phase 4).
 */
final class TenantAccessDeniedException extends \RuntimeException
{
}
