<?php

declare(strict_types=1);

namespace Glueful\Migrations;

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

/** Creates the central, globally unique tenant host registry. */
class CreateTenantDomainsTable implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if ($schema->hasTable('tenant_domains')) {
            return;
        }

        $schema->createTable('tenant_domains', function ($table): void {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('uuid', 12);
            $table->string('tenant_uuid', 12);
            $table->string('host', 255);
            $table->string('verification_status', 16)->default('pending');
            $table->string('status', 16)->default('active');
            $table->string('verification_token', 64)->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->string('last_check_status', 16)->nullable();
            $table->integer('consecutive_failures')->default(0);
            $table->timestamp('first_failure_at')->nullable();
            $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
            $table->timestamp('updated_at')->default('CURRENT_TIMESTAMP');

            $table->unique('uuid');
            $table->unique('host');
            $table->index('tenant_uuid');
            $table->index('status');
            $table->index(['verification_status', 'last_checked_at']);
            $table->foreign('tenant_uuid')
                ->references('uuid')
                ->on('tenants')
                ->cascadeOnDelete();
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('tenant_domains');
    }

    public function getDescription(): string
    {
        return 'Creates normalized tenant domains with independent verification and status.';
    }
}
