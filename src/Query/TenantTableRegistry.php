<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Query;

use Glueful\Bootstrap\ApplicationContext;

/**
 * Process-level set of tenant-owned table names.
 *
 * Populated at boot time as each {@see \Glueful\Extensions\Tenancy\ORM\Concerns\BelongsToTenant}
 * model boots (it calls {@see register()} with its table). This is intentionally static —
 * it is boot-time configuration, not per-request state.
 *
 * Phase 5 extends this with config-authoritative population (the `tenancy.tables` list)
 * and an auto-injection layer that READS this registry; that behaviour is NOT implemented here.
 */
final class TenantTableRegistry
{
    /** @var array<string, true> */
    private static array $tables = [];

    /**
     * Register a table as tenant-owned.
     */
    public static function register(string $table): void
    {
        self::$tables[$table] = true;
    }

    /**
     * Populate the registry from the AUTHORITATIVE `tenancy.tables` config list.
     *
     * Called from the provider's boot() so tenant-owned tables are registered BEFORE any
     * request runs queries — and independently of whether the corresponding BelongsToTenant
     * models have booted. This is what lets raw-query auto-injection protect a table from
     * the very first query, without relying on model boot order.
     */
    public static function loadFromConfig(ApplicationContext $context): void
    {
        /** @var array<int, string> $tables */
        $tables = \config($context, 'tenancy.tables', []);

        foreach ($tables as $table) {
            if (!is_string($table) || $table === '') {
                throw new \InvalidArgumentException('tenancy.tables must contain only non-empty table names.');
            }

            self::register($table);
        }
    }

    /**
     * Whether the given table is registered as tenant-owned.
     */
    public static function isTenantOwned(string $table): bool
    {
        return isset(self::$tables[$table]);
    }

    /**
     * All registered tenant-owned table names.
     *
     * @return array<int, string>
     */
    public static function all(): array
    {
        return array_keys(self::$tables);
    }

    /**
     * Clear the registry (primarily for test isolation).
     */
    public static function clear(): void
    {
        self::$tables = [];
    }
}
