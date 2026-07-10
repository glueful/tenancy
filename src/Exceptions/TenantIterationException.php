<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Exceptions;

/**
 * Thrown by TenantContextRunner::forEachTenant when work for a tenant fails. Carries the
 * offending tenant uuid so fail-fast callers can report exactly where iteration stopped.
 */
final class TenantIterationException extends \RuntimeException
{
    public function __construct(
        public readonly string $tenantUuid,
        \Throwable $previous,
    ) {
        // Preserve the cause's code when it is a usable int (PDOException etc. may use a
        // string SQLSTATE — fall back to 0 there rather than passing a non-int to the parent).
        $code = is_int($previous->getCode()) ? $previous->getCode() : 0;

        parent::__construct(
            sprintf('forEachTenant failed for tenant "%s": %s', $tenantUuid, $previous->getMessage()),
            $code,
            $previous,
        );
    }
}
