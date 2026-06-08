<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Container\Definition\FactoryDefinition;
use Glueful\Database\Migrations\MigrationPriority;
use Glueful\Extensions\Tenancy\Authorization\TenantAccess;
use Glueful\Extensions\Tenancy\Http\TenantMiddleware;
use Glueful\Extensions\Tenancy\Resolution\ResolverChain;
use Glueful\Extensions\Tenancy\Resolution\ResolverFactory;
use Glueful\Extensions\Tenancy\Resolution\TenantResolutionPipeline;
use Glueful\Extensions\Tenancy\Strategy\RowLevelStrategy;
use Glueful\Extensions\Tenancy\Strategy\TenancyStrategyInterface;
use Psr\Container\ContainerInterface;

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
            // Bypass decider — plain, dependency-free collaborator the pipeline injects.
            TenantAccess::class => [
                'class' => TenantAccess::class,
                'shared' => true,
                'autowire' => true,
            ],
            // The resolver chain's ORDER is config-driven (config('tenancy.resolvers')),
            // so it must be built at runtime from the resolved context — hence a factory,
            // not plain autowiring.
            ResolverChain::class => new FactoryDefinition(
                ResolverChain::class,
                static fn(ContainerInterface $c): ResolverChain =>
                    ResolverFactory::chain($c->get(ApplicationContext::class))
            ),
            // Autowired from ResolverChain (factory above) + TenantAccess.
            TenantResolutionPipeline::class => [
                'class' => TenantResolutionPipeline::class,
                'shared' => true,
                'autowire' => true,
            ],
        ];
    }

    public function register(ApplicationContext $context): void
    {
        $this->mergeConfig('tenancy', require __DIR__ . '/../config/tenancy.php');

        // Directory may not exist yet — loadMigrationsFrom no-ops if absent.
        $this->loadMigrationsFrom(
            __DIR__ . '/../migrations',
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
