<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Database\Execution\QueryExecutor;
use Glueful\Database\Migrations\MigrationPriority;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Extensions\Contracts\Tenancy\TenantContextRunner;
use Glueful\Extensions\Contracts\Tenancy\TenantEnforcementProbe;
use Glueful\Extensions\Contracts\Tenancy\TenantProvisioner;
use Glueful\Extensions\Contracts\Tenancy\TenantProvisioningRunner;
use Glueful\Extensions\Contracts\Tenancy\TenantAdministration;
use Glueful\Extensions\Contracts\Tenancy\TenantDomainAdministration;
use Glueful\Extensions\Contracts\Tenancy\TenantResolutionProbe;
use Glueful\Extensions\Contracts\Tenancy\TenantRequestMiddleware as TenantRequestMiddlewareContract;
use Glueful\Extensions\Contracts\Tenancy\TenantTableRegistry as TenantTableRegistryContract;
use Glueful\Extensions\Tenancy\Authorization\TenantAccess;
use Glueful\Extensions\Tenancy\Bridge\ContractTableRegistry;
use Glueful\Extensions\Tenancy\Bridge\ContractEnforcementProbe;
use Glueful\Extensions\Tenancy\Bridge\ContractTenantProvisioner;
use Glueful\Extensions\Tenancy\Bridge\ContractTenantProvisioningRunner;
use Glueful\Extensions\Tenancy\Bridge\ContractTenantAdministration;
use Glueful\Extensions\Tenancy\Bridge\ContractTenantDomainAdministration;
use Glueful\Extensions\Tenancy\Bridge\ContractTenantResolutionProbe;
use Glueful\Extensions\Tenancy\Bridge\ContractTenantRunner;
use Glueful\Extensions\Tenancy\Bridge\ContractTenantResolver;
use Glueful\Extensions\Tenancy\Context\CurrentContext;
use Glueful\Extensions\Tenancy\Cooldown\ReleasedHostRepository;
use Glueful\Extensions\Tenancy\Http\TenantMiddleware;
use Glueful\Extensions\Tenancy\Models\Tenant;
use Glueful\Extensions\Tenancy\Query\TenantInsertStamper;
use Glueful\Extensions\Tenancy\Query\TenantQueryGuard;
use Glueful\Extensions\Tenancy\Query\TenantTableRegistry;
use Glueful\Extensions\Tenancy\Resolution\ResolverChain;
use Glueful\Extensions\Tenancy\Resolution\ResolverFactory;
use Glueful\Extensions\Tenancy\Resolution\TenantResolutionPipeline;
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
            TenantRequestMiddlewareContract::class => [
                'factory' => [self::class, 'makeTenantRequestMiddleware'],
                'shared' => true,
            ],
            CurrentTenantResolver::class => [
                'class' => ContractTenantResolver::class,
                'shared' => true,
            ],
            TenantTableRegistryContract::class => [
                'class' => ContractTableRegistry::class,
                'shared' => true,
            ],
            TenantEnforcementProbe::class => [
                'class' => ContractEnforcementProbe::class,
                'shared' => true,
            ],
            TenantContextRunner::class => [
                'class' => ContractTenantRunner::class,
                'shared' => true,
                'autowire' => true,
            ],
            TenantProvisioner::class => [
                'class' => ContractTenantProvisioner::class,
                'shared' => true,
                'autowire' => true,
            ],
            TenantProvisioningRunner::class => [
                'class' => ContractTenantProvisioningRunner::class,
                'shared' => true,
                'autowire' => true,
            ],
            TenantAdministration::class => [
                'class' => ContractTenantAdministration::class,
                'shared' => true,
                'autowire' => true,
            ],
            TenantDomainAdministration::class => [
                'class' => ContractTenantDomainAdministration::class,
                'shared' => true,
                'autowire' => true,
            ],
            ReleasedHostRepository::class => [
                'class' => ReleasedHostRepository::class,
                'shared' => true,
                'autowire' => true,
            ],
            TenantResolutionProbe::class => [
                'class' => ContractTenantResolutionProbe::class,
                'shared' => true,
                'autowire' => true,
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
            // not plain autowiring. A named (non-closure) factory keeps this DSL spec
            // production-safe; the DSL services() loader rejects closures for production.
            ResolverChain::class => [
                'factory' => [ResolverFactory::class, 'chainFromContainer'],
                'shared' => true,
            ],
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
    }

    public static function makeTenantRequestMiddleware(
        \Psr\Container\ContainerInterface $container
    ): TenantRequestMiddlewareContract {
        return $container->get(TenantMiddleware::class);
    }

    public function boot(ApplicationContext $context): void
    {
        // Run AFTER the identity store (IDENTITY = -100) but BEFORE app/feature
        // migrations (DEFAULT = 0), so `tenants` exists before any app tenant-owned
        // table that FKs to `tenants.uuid`. NOT FOUNDATION (-200) — that tier is
        // reserved for framework core; and NOT DEPENDENT (100) — that runs after the
        // app, which would create `tenants` too late. A raw int between the tiers is
        // the framework-sanctioned way to order an extension's infrastructure here.
        // Directory may not exist yet — loadMigrationsFrom no-ops if absent.
        $this->loadMigrationsFrom(
            __DIR__ . '/../migrations',
            MigrationPriority::DEFAULT - 50,
            'glueful/tenancy'
        );

        try {
            if (\config($context, 'tenancy.enabled', true) === true) {
                // The config `tenancy.tables` list is the AUTHORITATIVE registry of tenant-owned
                // tables. Populate it at boot — before any request runs a query — so raw-query
                // auto-injection protects those tables regardless of model boot order. The
                // BelongsToTenant trait still registers as a backstop.
                TenantTableRegistry::loadFromConfig($context);

                // Install the primary-table auto-injection hook on the query builder.
                self::registerTableHook();

                // Install the pre-execution safety net: the TenantQueryGuard catches raw/unscoped
                // access to tenant-owned tables that the auto-injection hook never saw. Registered
                // via the CHAINABLE interceptor seam so host/other interceptors still run.
                QueryExecutor::addQueryInterceptor(new TenantQueryGuard());

                // Write-side stamper: fill tenant_uuid on builder inserts into owned tables — the
                // symmetric counterpart to the read table-hook above. Requires the framework's
                // Connection::addInsertHook seam (pinned at release).
                Connection::addInsertHook(TenantInsertStamper::hook());
            }
        } catch (\Throwable $e) {
            error_log('[Tenancy] Failed to register tenant enforcement: ' . $e->getMessage());
            if ($context->getEnvironment() !== 'production') {
                throw $e;
            }
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
            // Accept aliased primary-table references ("posts as p" / "posts p"): resolve the REAL
            // owned table for the ownership check, but qualify the predicate by the ALIAS the query
            // actually uses. Injecting `posts.tenant_uuid` when the FROM clause aliases the table as
            // `p` is an invalid reference; and without alias-parsing the ownership check would miss
            // the table entirely, leaving the read unscoped for the guard to fail-close.
            [$realTable, $alias] = self::splitTableAlias($table);

            if (!TenantTableRegistry::isTenantOwned($realTable)) {
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

            // Qualify the primary-table predicate by the alias (defaults to the table name) so joined
            // reads against another table carrying tenant_uuid do not become ambiguous, and aliased
            // FROM clauses remain valid SQL.
            $qb->where($alias . '.tenant_uuid', $tenant->uuid);
        });
    }

    /**
     * Split a builder table reference into its real table name and the alias the query uses.
     * "posts as p" and "posts p" both yield ['posts', 'p']; a bare "posts" yields ['posts', 'posts'].
     * A reference that is not a simple `<table> [as] <alias>` pair is returned unchanged as its own
     * alias, so the ownership check simply no-ops on it.
     *
     * @return array{0: string, 1: string}
     */
    private static function splitTableAlias(string $table): array
    {
        $trimmed = trim($table);
        if (preg_match('/^(\S+)\s+(?:as\s+)?(\S+)$/i', $trimmed, $matches) === 1) {
            return [$matches[1], $matches[2]];
        }

        return [$trimmed, $trimmed];
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
