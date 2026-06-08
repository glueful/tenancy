<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Container\Definition\FactoryDefinition;
use Glueful\Database\Connection;
use Glueful\Database\Execution\QueryExecutor;
use Glueful\Database\Migrations\MigrationPriority;
use Glueful\Extensions\Tenancy\Authorization\TenantAccess;
use Glueful\Extensions\Tenancy\Context\CurrentContext;
use Glueful\Extensions\Tenancy\Http\TenantMiddleware;
use Glueful\Extensions\Tenancy\Models\Tenant;
use Glueful\Extensions\Tenancy\Query\TenantQueryGuard;
use Glueful\Extensions\Tenancy\Query\TenantTableRegistry;
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
        // The config `tenancy.tables` list is the AUTHORITATIVE registry of tenant-owned
        // tables. Populate it at boot — before any request runs a query — so raw-query
        // auto-injection protects those tables regardless of model boot order. The
        // BelongsToTenant trait still registers as a backstop.
        TenantTableRegistry::loadFromConfig($context);

        // Install the primary-table auto-injection hook on the query builder.
        self::registerTableHook();

        // Install the pre-execution safety net: the TenantQueryGuard catches raw/unscoped
        // access to tenant-owned tables that the auto-injection hook never saw. Registered via
        // the CHAINABLE interceptor seam so host/other interceptors still run. Gated on
        // tenancy.enabled so disabling the extension fully disarms enforcement.
        if (\config($context, 'tenancy.enabled', true) === true) {
            QueryExecutor::addQueryInterceptor(new TenantQueryGuard());
        }

        // Auto-discover the tenant:* console commands (each carries #[AsCommand]).
        $this->discoverCommands(
            'Glueful\\Extensions\\Tenancy\\Console',
            __DIR__ . '/Console'
        );
    }

    /**
     * Register the process-level Connection table hook that auto-injects the current
     * tenant's `tenant_uuid` predicate into every query against a tenant-owned table.
     *
     * The hook receives only ($qb, $table, $conn) — no ApplicationContext — so it reaches
     * the current request's context (and thus the active tenant) through the
     * {@see CurrentContext} holder. It is intentionally conservative: it adds a predicate
     * ONLY when there is a current context, no explicit bypass, and a concrete current
     * tenant. The "no tenant / fail-closed" decision is left to the ORM scope and the
     * Phase-6 query guard — this hook never blocks a query, it only narrows it.
     *
     * Registered via the CHAINABLE addTableHook() so host/other-extension hooks still run.
     */
    public static function registerTableHook(): void
    {
        Connection::addTableHook(static function ($qb, string $table, $conn): void {
            if (!TenantTableRegistry::isTenantOwned($table)) {
                return;
            }

            $ctx = CurrentContext::get();
            if ($ctx === null) {
                return;
            }

            // Explicit bypass (runAsSystem / runAsTenant / forAnyTenant): no predicate.
            if ($ctx->getRequestState('tenancy.bypass') !== null) {
                return;
            }

            // No current tenant: leave the query untouched for the guard/ORM to decide.
            $tenant = $ctx->getRequestState('tenancy.tenant');
            if (!$tenant instanceof Tenant) {
                return;
            }

            // Unqualified column (not `{$table}.tenant_uuid`): a table-qualified predicate
            // trips the framework's UPDATE/DELETE column validator on same-tenant raw writes
            // (it re-validates already-wrapped identifiers). Unqualified scopes reads and
            // writes uniformly. See TenantScope's docblock for the full rationale.
            $qb->where('tenant_uuid', $tenant->uuid);
        });
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
