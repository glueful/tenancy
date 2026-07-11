<?php

declare(strict_types=1);

namespace Glueful\Migrations;

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

/**
 * Tenants registry — the central, NON-tenant-scoped directory of tenants.
 *
 * This table lives in the shared/global space (it is never row-scoped by tenant). Every
 * tenant has a stable short uuid (the principal id used across the system) and a unique slug.
 * settings is a nullable JSON/text blob for per-tenant configuration; deleted_at supports
 * soft deletes.
 */
class CreateTenantsTable implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if ($schema->hasTable('tenants')) {
            return;
        }

        $schema->createTable('tenants', function ($table) {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('uuid', 12);
            $table->string('slug', 255);
            $table->string('name', 255);
            $table->string('status', 32)->default('active');
            $table->text('settings')->nullable();
            $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
            $table->timestamp('updated_at')->default('CURRENT_TIMESTAMP');
            $table->timestamp('deleted_at')->nullable();
            $table->string('deleted_from_status', 32)->nullable();
            $table->timestamp('purge_after')->nullable();

            $table->unique('uuid');
            $table->unique('slug');
            $table->index('status');
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        if (!$schema->hasTable('tenants')) {
            return;
        }

        $schema->dropTableIfExists('tenants');
    }

    public function getDescription(): string
    {
        return 'Creates the tenants registry table.';
    }
}
