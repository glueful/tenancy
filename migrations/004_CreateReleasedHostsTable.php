<?php

declare(strict_types=1);

namespace Glueful\Migrations;

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

/** System-global host cooldown ledger; releasing tenant UUIDs intentionally have no FK. */
final class CreateReleasedHostsTable implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if ($schema->hasTable('released_hosts')) {
            return;
        }

        $schema->createTable('released_hosts', function ($table): void {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('host', 255)->unique();
            $table->string('released_by_tenant', 12);
            $table->timestamp('retained_until');
            $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
            $table->index('released_by_tenant');
            $table->index('retained_until');
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('released_hosts');
    }

    public function getDescription(): string
    {
        return 'Creates the released-host cooldown ledger.';
    }
}
