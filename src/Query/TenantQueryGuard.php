<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Query;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Execution\QueryInterceptorInterface;
use Glueful\Extensions\Tenancy\Context\CurrentContext;
use Glueful\Extensions\Tenancy\Context\TenantContext;
use Glueful\Extensions\Tenancy\Exceptions\TenantScopeViolationException;
use Psr\Log\LoggerInterface;

/**
 * Pre-execution interceptor that catches raw/unscoped access to tenant-owned tables.
 *
 * Registered on {@see \Glueful\Database\Execution\QueryExecutor} via addQueryInterceptor(); its
 * before() runs inside executeStatement() BEFORE prepare/execute. It is the SAFETY NET behind the
 * primary-table auto-injection hook and the ORM scope: those narrow queries that go through the
 * normal builder/Model path, but a hand-written raw query (or a builder path the hook never sees)
 * can still slip an unscoped statement to the database. This guard inspects the final SQL text and
 * either throws (dev/test — so leaks fail loudly) or logs a metric (prod — never throws).
 *
 * Enforcement is request-scoped and bypass-aware: it only acts while a tenant request is in flight
 * (a {@see CurrentContext} is set) and no bypass is active. With no current context — migrations,
 * boot, CLI, background work — it is a no-op (no tenant to enforce against).
 *
 * The detector is deliberately CONSERVATIVE: it prefers false-negatives (let a query through) over
 * false-positives (block a legitimate query), because a false-positive in prod would be a hard
 * outage. See {@see referencesUnscopedTenantTable()} for the heuristic and its documented limits.
 */
final class TenantQueryGuard implements QueryInterceptorInterface
{
    /** Environments treated as "dev" (guard may throw). Everything else is prod (never throws). */
    private const DEV_ENVIRONMENTS = ['testing', 'dev', 'development', 'local'];

    /** Statement leading keywords that are never tenant-data access — skip them outright. */
    private const SKIP_PREFIXES = [
        'create', 'alter', 'drop', 'truncate', 'begin', 'commit', 'rollback', 'savepoint',
        'release', 'pragma', 'set ', 'explain', 'show', 'use ',
    ];

    /**
     * @param array<int|string, mixed> $bindings
     */
    public function before(string $sql, array $bindings): void
    {
        $ctx = CurrentContext::get();

        // No request in flight (migrations / boot / CLI): nothing to enforce against.
        if ($ctx === null) {
            return;
        }

        // Explicit bypass (runAsSystem / runAsTenant / forAnyTenant): privileged, no enforcement.
        if ($ctx->getRequestState('tenancy.bypass') !== null) {
            return;
        }

        $this->guardTenantUuidWrite($ctx, $sql, $bindings);

        $table = $this->referencesUnscopedTenantTable($sql);
        if ($table === null) {
            return;
        }

        $this->act($ctx, $table, $sql);
    }

    /**
     * Prevent raw writes from planting/reassigning rows into another tenant.
     *
     * The normal guard treats any tenant_uuid mention as scoped. That is correct for
     * reads, but unsafe for writes: `INSERT ... tenant_uuid = victim` and
     * `UPDATE ... SET tenant_uuid = victim WHERE tenant_uuid = current` both name
     * the column while crossing the tenant boundary. QueryBuilder emits positional
     * bindings in column order, so we can compare the written tenant_uuid value to
     * the active tenant.
     *
     * @param array<int|string,mixed> $bindings
     */
    private function guardTenantUuidWrite(ApplicationContext $ctx, string $sql, array $bindings): void
    {
        $tenant = (new TenantContext($ctx))->currentTenant();
        if ($tenant === null) {
            return;
        }

        $table = $this->tenantOwnedWriteTarget($sql);
        if ($table === null) {
            return;
        }

        $writeValue = $this->writtenTenantUuid($sql, array_values($bindings));
        if ($writeValue === null || (string) $writeValue === $tenant->uuid) {
            return;
        }

        throw new TenantScopeViolationException(sprintf(
            'Raw write to tenant-owned table "%s" attempted to write tenant_uuid "%s" while current tenant is "%s".',
            $table,
            (string) $writeValue,
            $tenant->uuid
        ));
    }

    private function tenantOwnedWriteTarget(string $sql): ?string
    {
        $lower = strtolower(ltrim($sql));
        if (!preg_match('/^(insert\s+into|update)\s+["`\']?([a-z0-9_.-]+)["`\']?/i', $lower, $matches)) {
            return null;
        }

        $table = $matches[2];

        return TenantTableRegistry::isTenantOwned($table) ? $table : null;
    }

    /**
     * @param list<mixed> $bindings
     */
    private function writtenTenantUuid(string $sql, array $bindings): mixed
    {
        $lower = strtolower($sql);

        if (preg_match('/^insert\s+into\s+["`\']?[a-z0-9_.-]+["`\']?\s*\(([^)]+)\)/i', $lower, $matches)) {
            $columns = $this->parseColumnList($matches[1]);
            $index = array_search('tenant_uuid', $columns, true);
            return $index === false ? null : ($bindings[$index] ?? null);
        }

        if (preg_match('/^update\s+["`\']?[a-z0-9_.-]+["`\']?\s+set\s+(.+?)(?:\s+where\s+|$)/i', $lower, $matches)) {
            $columns = $this->parseSetColumns($matches[1]);
            $index = array_search('tenant_uuid', $columns, true);
            return $index === false ? null : ($bindings[$index] ?? null);
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function parseColumnList(string $columns): array
    {
        return array_values(array_map(
            static fn(string $column): string => trim($column, " \t\n\r\0\x0B`\"'"),
            explode(',', $columns)
        ));
    }

    /**
     * @return list<string>
     */
    private function parseSetColumns(string $setClause): array
    {
        $columns = [];
        foreach (explode(',', $setClause) as $assignment) {
            $parts = explode('=', $assignment, 2);
            $columns[] = trim($parts[0], " \t\n\r\0\x0B`\"'");
        }

        return $columns;
    }

    /**
     * Conservative detector. Returns the first registered tenant-owned table that the SQL
     * references in a FROM/JOIN/UPDATE/INTO position WITHOUT any tenant_uuid predicate, or null.
     *
     * Heuristic:
     *   1. Lowercase the SQL; skip DDL / transaction-control statements outright.
     *   2. If the statement mentions `tenant_uuid` ANYWHERE, treat it as scoped (return null).
     *      Presence anywhere is sufficient — conservative: we'd rather miss a contrived unscoped
     *      query that happens to name the column than block a legitimately scoped one.
     *   3. For each registered table, look for it as a TABLE REFERENCE: immediately following
     *      `from`/`join`/`update`/`into`, allowing optional backtick/quote wrapping and a trailing
     *      word boundary (so an alias or clause may follow, but `invoices_archive` does NOT match
     *      `invoices`).
     */
    private function referencesUnscopedTenantTable(string $sql): ?string
    {
        $lower = strtolower(ltrim($sql));

        foreach (self::SKIP_PREFIXES as $prefix) {
            if (str_starts_with($lower, $prefix)) {
                return null;
            }
        }

        // Any tenant_uuid mention => considered scoped (conservative).
        if (str_contains($lower, 'tenant_uuid')) {
            return null;
        }

        $tables = TenantTableRegistry::all();
        if ($tables === []) {
            return null;
        }

        foreach ($tables as $table) {
            if ($this->referencesTableAsTarget($lower, strtolower($table))) {
                return $table;
            }
        }

        return null;
    }

    /**
     * Whether $lowerSql references $table as a FROM/JOIN/UPDATE/INTO target.
     *
     * Matches `<keyword> [`"']? <table> [`"']?` at a word boundary. The table name is quoted for
     * the regex so identifiers with regex-significant characters are handled literally.
     */
    private function referencesTableAsTarget(string $lowerSql, string $table): bool
    {
        $quoted = preg_quote($table, '/');
        // keyword + whitespace + optional opening quote/backtick + table + word boundary
        $pattern = '/\b(?:from|join|update|into)\s+["`\']?' . $quoted . '(?:["`\']?)(?=\s|$|[,)";])/';

        return preg_match($pattern, $lowerSql) === 1;
    }

    /**
     * Act on a detected violation per environment + config.
     *
     * Dev/test: if guard.dev (default 'throw') is 'throw', throw so the leak fails loudly.
     * Prod: per guard.prod (default 'metric') — 'metric'/'log' emit a warning log; 'off' no-ops.
     * Production NEVER throws.
     */
    private function act(ApplicationContext $ctx, string $table, string $sql): void
    {
        if ($this->isDevEnvironment($ctx)) {
            $mode = (string) \config($ctx, 'tenancy.enforcement.guard.dev', 'throw');
            if ($mode === 'throw') {
                throw new TenantScopeViolationException(sprintf(
                    'Unscoped query against tenant-owned table "%s" (no tenant_uuid predicate): %s',
                    $table,
                    $sql
                ));
            }
            // Any non-'throw' dev value falls through to the same observe path as prod.
        }

        $mode = (string) \config($ctx, 'tenancy.enforcement.guard.prod', 'metric');
        if ($mode === 'off') {
            return;
        }

        // 'metric' | 'log' (and any other non-off value) => emit a warning.
        $this->emitWarning($ctx, $table, $sql);
    }

    private function isDevEnvironment(ApplicationContext $ctx): bool
    {
        return in_array($ctx->getEnvironment(), self::DEV_ENVIRONMENTS, true);
    }

    /**
     * Emit a tagged warning (and a metric, if a metrics collector is trivially available).
     *
     * The logger is resolved DEFENSIVELY from the current context's container — the guard must
     * never hard-depend on a logging/metrics service, since it runs deep in the DB layer where a
     * missing binding would otherwise turn a warning into a fatal. No logger => silently skip.
     */
    private function emitWarning(ApplicationContext $ctx, string $table, string $sql): void
    {
        $logger = $this->resolveLogger($ctx);
        $logger?->warning('Unscoped query against tenant-owned table', [
            'event' => 'tenancy.unscoped_query',
            'table' => $table,
            'sql' => $sql,
        ]);
    }

    private function resolveLogger(ApplicationContext $ctx): ?LoggerInterface
    {
        if (!$ctx->hasContainer()) {
            return null;
        }

        $container = $ctx->getContainer();
        foreach (['logger', LoggerInterface::class] as $id) {
            if ($container->has($id)) {
                $logger = $container->get($id);
                if ($logger instanceof LoggerInterface) {
                    return $logger;
                }
            }
        }

        return null;
    }
}
