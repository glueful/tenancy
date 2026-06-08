<?php

declare(strict_types=1);

namespace Glueful\Migrations;

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

/**
 * Tenant memberships — the bridge between GLOBAL users and tenants.
 *
 * A membership grants a (global) user a role within a tenant. tenant_uuid carries a hard FK to
 * tenants(uuid) — both tables are owned by this package, so intra-package referential integrity is
 * fine. user_uuid is an INDEXED uuid with NO FK: the user store is a separate package and the user
 * is an external principal id; existence is validated in the service layer (never via a cross-package
 * FK). The (tenant_uuid, user_uuid) pair is unique — a user has at most one membership per tenant.
 */
class CreateTenantMembershipsTable implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if ($schema->hasTable('tenant_memberships')) {
            return;
        }

        $schema->createTable('tenant_memberships', function ($table) {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('uuid', 12);
            $table->string('tenant_uuid', 12);
            $table->string('user_uuid', 12);
            $table->string('role', 64)->default('member');
            $table->string('status', 32)->default('active');
            $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
            $table->timestamp('updated_at')->default('CURRENT_TIMESTAMP');

            $table->unique('uuid');
            $table->index('tenant_uuid');
            $table->index('user_uuid'); // indexed only — external principal id, no FK
            $table->unique(['tenant_uuid', 'user_uuid']);

            // Intra-package FK: a membership belongs to a tenant in this package's registry.
            $table->foreign('tenant_uuid')
                ->references('uuid')
                ->on('tenants')
                ->cascadeOnDelete();
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        if (!$schema->hasTable('tenant_memberships')) {
            return;
        }

        $schema->dropTableIfExists('tenant_memberships');
    }

    public function getDescription(): string
    {
        return 'Creates the tenant_memberships bridge table.';
    }
}
