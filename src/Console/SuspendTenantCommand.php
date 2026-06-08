<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Console;

use Glueful\Console\BaseCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `tenant:suspend` — flip a tenant's status to `suspended`.
 *
 * Suspended tenants still exist (they are not soft-deleted) but fail the active-status check in
 * the resolution pipeline, so they cannot resolve for a request. Looked up by slug; the update
 * is a direct query-builder write (not Model::save()).
 */
#[AsCommand(name: 'tenant:suspend', description: 'Suspend a tenant')]
final class SuspendTenantCommand extends BaseCommand
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

        db($ctx)->table('tenants')->where('slug', $slug)->update(['status' => 'suspended']);

        $this->success("Tenant '{$slug}' suspended.");
        return self::SUCCESS;
    }
}
