<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Console;

use Glueful\Console\BaseCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `tenant:list` — print the central tenants directory as a table.
 *
 * Soft-deleted tenants are excluded (the query builder filters deleted_at IS NULL on tables
 * carrying the column).
 */
#[AsCommand(name: 'tenant:list', description: 'List all tenants')]
final class ListTenantsCommand extends BaseCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $ctx = $this->getContext();

        $tenants = db($ctx)->table('tenants')
            ->select(['uuid', 'slug', 'name', 'status'])
            ->get();

        if ($tenants === []) {
            $this->info('No tenants.');
            return self::SUCCESS;
        }

        $rows = [];
        foreach ($tenants as $tenant) {
            $rows[] = [
                (string) ($tenant['uuid'] ?? ''),
                (string) ($tenant['slug'] ?? ''),
                (string) ($tenant['name'] ?? ''),
                (string) ($tenant['status'] ?? ''),
            ];
        }

        $this->table(['UUID', 'Slug', 'Name', 'Status'], $rows);
        return self::SUCCESS;
    }
}
