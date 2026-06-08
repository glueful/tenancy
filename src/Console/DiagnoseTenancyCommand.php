<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Console;

use Glueful\Console\BaseCommand;
use Glueful\Extensions\Tenancy\Query\TenantTableRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `tenant:diagnose` — a read-only health report for the tenancy setup.
 *
 * Reports three sections:
 *   (b) Registered tenant-owned tables (from {@see TenantTableRegistry::all()}, after a
 *       config reload so the report reflects current config).
 *   (a) Schema drift — every registered tenant-owned table MUST carry a `tenant_uuid` column;
 *       any that does not is flagged. Column presence is checked with the framework's portable
 *       schema introspection: {@see \Glueful\Database\Schema\Interfaces\SchemaBuilderInterface::hasColumn()}.
 *   (c) Membership integrity — tenant_memberships rows whose tenant_uuid has no matching
 *       tenants.uuid (orphans).
 *
 * This is a REPORT, not a gate: it returns SUCCESS even when it surfaces warnings, but the
 * warnings are rendered prominently.
 */
#[AsCommand(
    name: 'tenant:diagnose',
    description: 'Diagnose the tenancy setup (registered tables, schema drift, membership integrity)'
)]
final class DiagnoseTenancyCommand extends BaseCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $ctx = $this->getContext();

        // Reflect current config in the registry before reporting.
        TenantTableRegistry::loadFromConfig($ctx);
        $tables = TenantTableRegistry::all();

        $this->reportRegisteredTables($tables);
        $this->reportSchemaDrift($tables);
        $this->reportMembershipIntegrity();

        return self::SUCCESS;
    }

    /**
     * (b) Registered tenant-owned tables.
     *
     * @param array<int, string> $tables
     */
    private function reportRegisteredTables(array $tables): void
    {
        $this->line('');
        $this->line('<info>Registered tenant-owned tables</info>');

        if ($tables === []) {
            $this->note('No tenant-owned tables registered.');
            return;
        }

        $this->table(['Table'], array_map(static fn(string $t): array => [$t], $tables));
    }

    /**
     * (a) Schema drift — registered tenant-owned tables missing a tenant_uuid column.
     *
     * Uses SchemaBuilderInterface::hasColumn(), a portable introspection method backed by
     * driver-specific column-existence queries (PRAGMA table_info on SQLite,
     * information_schema elsewhere). Tables that do not exist are reported separately.
     *
     * @param array<int, string> $tables
     */
    private function reportSchemaDrift(array $tables): void
    {
        $this->line('');
        $this->line('<info>Schema drift</info>');

        if ($tables === []) {
            $this->note('Nothing to check.');
            return;
        }

        $schema = db($this->getContext())->getSchemaBuilder();
        $drift = false;

        foreach ($tables as $table) {
            if (!$schema->hasTable($table)) {
                $this->warning("Registered table '{$table}' does not exist in the database.");
                $drift = true;
                continue;
            }

            if (!$schema->hasColumn($table, 'tenant_uuid')) {
                $this->warning(
                    "Schema drift: table '{$table}' is registered as tenant-owned but has no tenant_uuid column."
                );
                $drift = true;
            }
        }

        if (!$drift) {
            $this->note('No schema drift detected — every registered table carries tenant_uuid.');
        }
    }

    /**
     * (c) Membership integrity — orphan memberships (tenant_uuid with no matching tenant).
     */
    private function reportMembershipIntegrity(): void
    {
        $this->line('');
        $this->line('<info>Membership integrity</info>');

        $ctx = $this->getContext();
        $schema = db($ctx)->getSchemaBuilder();

        if (!$schema->hasTable('tenant_memberships')) {
            $this->note('No tenant_memberships table — nothing to check.');
            return;
        }

        // Collect every known tenant uuid (including soft-deleted: an orphan is "no row at all").
        $tenantUuids = array_values(array_filter(array_map(
            static fn(array $row): string => (string) ($row['uuid'] ?? ''),
            db($ctx)->table('tenants')->select(['uuid'])->get()
        )));

        $query = db($ctx)->table('tenant_memberships');
        if ($tenantUuids !== []) {
            $query->whereNotIn('tenant_uuid', $tenantUuids);
        }
        $orphans = $query->count();

        if ($orphans > 0) {
            $this->warning("Membership integrity: {$orphans} orphan membership(s) reference a missing tenant.");
            return;
        }

        $this->note('No orphan memberships — every membership references an existing tenant.');
    }
}
