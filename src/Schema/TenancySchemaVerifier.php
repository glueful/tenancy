<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Schema;

use Glueful\Database\Connection;
use Glueful\Extensions\Schema\StructuralVerifierInterface;

/**
 * Structural verifier for glueful/tenancy's CONTROL-PLANE descriptor (schema policy spec
 * B6/B7): core mode, platform priority — provisions with every install. The enforcement
 * lifecycle stays in the host's protected tenancy state machine; this class only proves the
 * control-plane tables for readiness/adoption. Unknown basenames are never adoptable.
 */
final class TenancySchemaVerifier implements StructuralVerifierInterface
{
    public function source(): string
    {
        return 'glueful/tenancy';
    }

    /** @return list<string> */
    public function migrationBasenames(): array
    {
        return [
            '001_CreateTenantsTable.php',
            '002_CreateTenantMembershipsTable.php',
            '003_CreateTenantDomainsTable.php',
            '004_CreateReleasedHostsTable.php',
        ];
    }

    public function verify(Connection $db, string $migrationBasename): bool
    {
        return match ($migrationBasename) {
            '001_CreateTenantsTable.php' => $this->tablesWithColumns($db, [
                'tenants' => ['name', 'slug', 'status'],
            ]),
            '002_CreateTenantMembershipsTable.php' => $this->tablesWithColumns($db, [
                'tenant_memberships' => ['tenant_uuid', 'user_uuid', 'role', 'status'],
            ]),
            '003_CreateTenantDomainsTable.php' => $this->tablesWithColumns($db, [
                'tenant_domains' => ['tenant_uuid', 'host', 'status'],
            ]),
            '004_CreateReleasedHostsTable.php' => $this->tablesWithColumns($db, [
                'released_hosts' => ['host', 'released_by_tenant'],
            ]),
            default => false,
        };
    }

    /** @param array<string, list<string>> $expectations */
    private function tablesWithColumns(Connection $db, array $expectations): bool
    {
        $schema = $db->getSchemaBuilder();
        foreach ($expectations as $table => $columns) {
            if (!$schema->hasTable($table)) {
                return false;
            }
            foreach ($columns as $column) {
                if (!$schema->hasColumn($table, $column)) {
                    return false;
                }
            }
        }
        return true;
    }
}
