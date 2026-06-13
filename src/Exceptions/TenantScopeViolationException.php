<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Exceptions;

/**
 * Thrown by {@see \Glueful\Extensions\Tenancy\Query\TenantQueryGuard} when a query reaches the
 * database referencing a registered tenant-owned table unsafely while a tenant request is in
 * flight and no bypass is active.
 *
 * This is the pre-execution safety net: unscoped reads throw in dev/test and log/metric in
 * production, while explicit cross-tenant tenant_uuid writes throw in every environment because
 * they are positive write-integrity violations.
 */
final class TenantScopeViolationException extends \RuntimeException
{
}
