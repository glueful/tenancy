<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Console;

use Glueful\Console\BaseCommand;
use Glueful\Helpers\Utils;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `tenant:create` — register a new tenant in the central tenants directory.
 *
 * Rejects a duplicate slug (the slug is the stable, human-facing tenant key and is UNIQUE in
 * the schema). The write goes through the raw query builder rather than the ORM Model::save()
 * to sidestep the soft-delete column-quoting quirk on the in-memory SQLite harness.
 */
#[AsCommand(name: 'tenant:create', description: 'Create a new tenant')]
final class CreateTenantCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this
            ->addOption('slug', null, InputOption::VALUE_REQUIRED, 'Unique tenant slug')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Tenant display name')
            ->addOption('status', null, InputOption::VALUE_REQUIRED, 'Tenant status', 'active');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $slug = $input->getOption('slug');
        $name = $input->getOption('name');
        $status = $input->getOption('status') ?? 'active';

        if (!is_string($slug) || $slug === '') {
            $this->error('The --slug option is required.');
            return self::FAILURE;
        }

        if (!is_string($name) || $name === '') {
            $this->error('The --name option is required.');
            return self::FAILURE;
        }

        $ctx = $this->getContext();

        $existing = db($ctx)->table('tenants')->where('slug', $slug)->first();
        if ($existing !== null) {
            $this->error("A tenant with slug '{$slug}' already exists.");
            return self::FAILURE;
        }

        $uuid = Utils::generateNanoID(12);
        db($ctx)->table('tenants')->insert([
            'uuid' => $uuid,
            'slug' => $slug,
            'name' => $name,
            'status' => is_string($status) && $status !== '' ? $status : 'active',
        ]);

        $this->success("Tenant '{$slug}' created ({$uuid}).");
        return self::SUCCESS;
    }
}
