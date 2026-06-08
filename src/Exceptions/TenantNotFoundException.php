<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Exceptions;

/**
 * Thrown when no usable tenant can be resolved: no candidate on a required route, or a
 * candidate that maps to no tenant — or to an inactive one.
 *
 * Inactive tenants deliberately raise the SAME exception as nonexistent ones so a client
 * can never distinguish "suspended" from "never existed". Mapped to HTTP 404 by the
 * tenant-resolution middleware (Phase 4).
 */
final class TenantNotFoundException extends \RuntimeException
{
}
