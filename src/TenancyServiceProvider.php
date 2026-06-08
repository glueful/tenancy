<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Migrations\MigrationPriority;
use Glueful\Extensions\Tenancy\Http\TenantMiddleware;
use Glueful\Extensions\Tenancy\Strategy\RowLevelStrategy;
use Glueful\Extensions\Tenancy\Strategy\TenancyStrategyInterface;

final class TenancyServiceProvider extends \Glueful\Extensions\ServiceProvider
{
    /**
     * Service definitions (array DSL).
     *
     * The `tenant` middleware alias is declared here — as a container alias of
     * TenantMiddleware — because the router resolves string middleware names
     * through the container, and the container compiles before boot(). Declaring
     * the alias in boot() would be too late, so registration must happen here.
     *
     * @return array<string, mixed>
     */
    public static function services(): array
    {
        return [
            TenantMiddleware::class => [
                'class' => TenantMiddleware::class,
                'shared' => true,
                'autowire' => true,
                'alias' => ['tenant'],
            ],
            RowLevelStrategy::class => [
                'class' => RowLevelStrategy::class,
                'shared' => true,
                'autowire' => true,
                'alias' => [TenancyStrategyInterface::class],
            ],
        ];
    }

    public function register(ApplicationContext $context): void
    {
        $this->mergeConfig('tenancy', require __DIR__ . '/../config/tenancy.php');

        // Directory may not exist yet — loadMigrationsFrom no-ops if absent.
        $this->loadMigrationsFrom(
            __DIR__ . '/../database/migrations',
            MigrationPriority::FOUNDATION,
            'glueful/tenancy'
        );
    }

    public function boot(ApplicationContext $context): void
    {
        // Minimal for now — no commands yet.
    }

    /**
     * Docs/tests-only helper. NOT the registration path — the `tenant` alias is
     * registered via the container in services().
     *
     * @return array<string, class-string>
     */
    public static function middlewareAliases(): array
    {
        return ['tenant' => TenantMiddleware::class];
    }
}
