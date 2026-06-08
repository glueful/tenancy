<?php

declare(strict_types=1);

namespace Glueful\Extensions\Tenancy\Context;

use Glueful\Bootstrap\ApplicationContext;

/**
 * Process-level pointer to the CURRENT request's {@see ApplicationContext}.
 *
 * The DB layer's table hook (primary-table auto-injection) and the Phase-5/6 query guard
 * run inside {@see \Glueful\Database\Connection::table()} / the query executor, which hand
 * the hook only ($qb, $table, $conn) — they have NO ApplicationContext, and the connection
 * carries no tenant. The active tenant + bypass mode live on the request-scoped
 * {@see ApplicationContext::requestState}. This holder bridges that gap: it exposes the
 * current context so a DB-layer hook can read request-scoped tenancy state.
 *
 * It is set by the `tenant` middleware for the duration of the request (try/finally) and by
 * tests. The tenant DATA itself is NOT stored here — it stays on requestState; this holder
 * only points at the context that owns that state. Mirrors the framework's own
 * {@see \Glueful\Events\QueueContextHolder}.
 *
 * CAVEAT — concurrency model. This assumes the PHP-FPM / shared-nothing model: one request
 * per process, so a single static pointer is safe and is cleared in the middleware's
 * `finally`. Under a concurrent/long-lived runtime (Swoole, RoadRunner, fibers) a single
 * static would leak the context across overlapping requests; such runtimes would need
 * per-fiber/per-coroutine storage instead. That is a known limitation, not yet handled here.
 */
final class CurrentContext
{
    private static ?ApplicationContext $context = null;

    /**
     * Point the holder at the current request's context (or clear it with null).
     */
    public static function set(?ApplicationContext $context): void
    {
        self::$context = $context;
    }

    /**
     * The current request's context, or null when none is active.
     */
    public static function get(): ?ApplicationContext
    {
        return self::$context;
    }

    /**
     * Clear the pointer (called in the middleware `finally` and by tests).
     */
    public static function clear(): void
    {
        self::$context = null;
    }
}
