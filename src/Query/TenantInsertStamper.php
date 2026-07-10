<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Query;

use Glueful\Extensions\Tenancy\Context\CurrentContext;
use Glueful\Extensions\Tenancy\Exceptions\TenantScopeViolationException;
use Glueful\Extensions\Tenancy\Models\Tenant;

/**
 * Write-side counterpart to the read table-hook: stamps the current tenant's tenant_uuid onto
 * inserts into registered tenant-owned tables. Registered via Connection::addInsertHook so
 * every builder insert/insertBatch/upsert flows through it. Raw PDO writes bypass this (and the
 * guard) and must be handled explicitly by the consuming app.
 */
final class TenantInsertStamper
{
    /** @return \Closure(string, array<string,mixed>):array<string,mixed> */
    public static function hook(): \Closure
    {
        return static function (string $table, array $data): array {
            if (!TenantTableRegistry::isTenantOwned($table)) {
                return $data;
            }

            $ctx = CurrentContext::get();
            if ($ctx === null) {
                // DOCUMENTED EXCEPTION: no CurrentContext => framework migrations / boot / CLI
                // without a runAsTenant wrapper. We do NOT throw here (that would break
                // legitimate migrations). It is SAFE because, once the schema is widened,
                // tenant_uuid is NOT NULL — an unstamped APPLICATION write fails loudly at the
                // DB, it cannot silently persist an unscoped row. Application writes must always
                // carry context (the required `tenant` middleware in a request; runAsTenant/
                // runAsSystem for seeders/jobs/CLI) and must NEVER rely on this branch.
                return $data;
            }

            // Explicit bypass (runAsSystem / forAnyTenant): unscoped write is intentional.
            if ($ctx->getRequestState('tenancy.bypass') !== null) {
                return $data;
            }

            $tenant = $ctx->getRequestState('tenancy.tenant');
            if (!$tenant instanceof Tenant) {
                // Live context, no bypass, tenant-owned write, but no tenant resolved => leak.
                throw new TenantScopeViolationException(sprintf(
                    'Insert into tenant-owned table "%s" with no current tenant (fail-closed).',
                    $table,
                ));
            }

            $supplied = $data['tenant_uuid'] ?? null;
            if ($supplied !== null && $supplied !== '') {
                // The hook holds the payload directly, so it enforces cross-tenant writes here
                // rather than relying on the SQL-text guard: a supplied tenant_uuid that differs
                // from the current tenant (and we are NOT in bypass) is a boundary violation.
                if ((string) $supplied !== $tenant->uuid) {
                    throw new TenantScopeViolationException(sprintf(
                        'Insert into tenant-owned table "%s" supplied tenant_uuid "%s" while current tenant is "%s".',
                        $table,
                        (string) $supplied,
                        $tenant->uuid,
                    ));
                }

                return $data; // matches the current tenant — leave as supplied
            }

            $data['tenant_uuid'] = $tenant->uuid;

            return $data;
        };
    }
}
