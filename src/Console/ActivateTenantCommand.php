<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Console;

use Glueful\Console\BaseCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `tenant:activate` — flip a tenant's status to `active`.
 *
 * Looked up by slug. The status flip is a direct query-builder update (not Model::save()) to
 * avoid the soft-delete column-quoting quirk on the in-memory SQLite harness.
 */
#[AsCommand(name: 'tenant:activate', description: 'Activate a tenant')]
final class ActivateTenantCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addArgument('slug', InputArgument::REQUIRED, 'Tenant slug');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $slug = (string) $input->getArgument('slug');
        $ctx = $this->getContext();

        $tenant = db($ctx)->table('tenants')->where('slug', $slug)->first();
        if ($tenant === null) {
            $this->error("No tenant found with slug '{$slug}'.");
            return self::FAILURE;
        }

        db($ctx)->table('tenants')->where('slug', $slug)->update(['status' => 'active']);

        $this->success("Tenant '{$slug}' activated.");
        return self::SUCCESS;
    }
}
