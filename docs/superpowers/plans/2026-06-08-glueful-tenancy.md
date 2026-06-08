# Glueful Tenancy Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build `glueful/tenancy` — a shared-database, row-level (table-relationship) multi-tenancy extension — on top of two small additive framework seams.

**Architecture:** Phase 0 adds two generic, non-breaking core seams to the framework (a pre-execution query interceptor and a `Connection::table()` decorator) as a standalone PR. Phases 1–9 build the extension: a `tenants`/`tenant_memberships` registry, a request-scoped `TenantContext` stored in `ApplicationContext::requestState`, a validated resolver pipeline + `tenant` middleware, a `BelongsToTenant` ORM trait + `TenantScope` (mirroring `SoftDeletes`/`SoftDeletingScope`), and non-ORM enforcement (auto-injection on the primary table via seam #2 + a `TenantQueryGuard` interceptor via seam #1 that throws in dev/test and emits metrics in prod). Many tenants per user is the default; bypass is explicit and permission-gated with noisy, tenancy-specific names.

**Tech Stack:** PHP 8.3+, Glueful framework, PHPUnit (SQLite `:memory:`), Glueful ORM (`Glueful\Database\ORM\*`), `Glueful\Bootstrap\ApplicationContext`.

**Spec:** `docs/superpowers/specs/2026-06-08-glueful-tenancy-design.md` (read it first).

**Repos / paths:**
- Framework (Phase 0): `/Users/michaeltawiahsowah/Sites/glueful/framework`
- Extension (Phases 1–9): `/Users/michaeltawiahsowah/Sites/glueful/extensions/tenancy` (new repo, package `glueful/tenancy`)

**Conventions:** work on `dev`; no `Co-Authored-By` trailers; UUIDs are `string(12)`; tests use the framework `TestCase` + SQLite `:memory:`.

**Phase 0 gating:** the extension's enforcement (auto-injection, prevention guard) depends on Phase 0. Phase 0 is a separate framework PR that **merges first**. If it slips, the extension still ships with the documented fallback (explicit `tenantTable()` + detection-only guard) — but the default plan assumes Phase 0 lands.

---

## Phase 0 — Core seams (framework repo PR)

### Task 0.1: Pre-execution query interceptor in `QueryExecutor`

A **chainable set of interceptors** fired in `executeStatement()` **before** `prepare()`/`execute()`; each may throw to prevent the query. Multiple may register (all run, in registration order — no last-writer-wins). Registration is process-level (boot-time config); per-request data comes from each interceptor reading request-scoped context — no per-request state stored statically.

**Files:**
- Modify: `framework/src/Database/Execution/QueryExecutor.php` (add interceptor field + invocation in `executeStatement()` ~line 163–188)
- Create: `framework/src/Database/Execution/QueryInterceptorInterface.php`
- Test: `framework/tests/Unit/Database/Execution/QueryInterceptorTest.php`

- [ ] **Step 1: Read the chokepoint.** Open `src/Database/Execution/QueryExecutor.php` and confirm `executeStatement(string $sql, array $bindings): PDOStatement` flattens params and calls `$this->pdo->prepare($sql)` then `$stmt->execute(...)`. The interceptor call goes at the **top** of that method (before `prepare`).

- [ ] **Step 2: Write the failing test.**

```php
<?php
namespace Glueful\Tests\Unit\Database\Execution;

use Glueful\Database\Execution\QueryExecutor;
use PHPUnit\Framework\TestCase;

final class QueryInterceptorTest extends TestCase
{
    protected function tearDown(): void { QueryExecutor::clearQueryInterceptors(); }

    public function test_all_registered_interceptors_run_in_order_and_any_can_prevent(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE t (id INTEGER PRIMARY KEY, n TEXT)');
        $executor = new QueryExecutor($pdo, new \Glueful\Database\Execution\ParameterBinder(), new \Glueful\Database\QueryLogger());

        $order = [];
        QueryExecutor::addQueryInterceptorCallback(function (string $sql, array $b) use (&$order) { $order[] = 'a'; });
        QueryExecutor::addQueryInterceptorCallback(function (string $sql, array $b) use (&$order) {
            $order[] = 'b';
            if (str_contains($sql, 'INSERT')) { throw new \RuntimeException('blocked'); }
        });

        try {
            $executor->executeStatement('INSERT INTO t (n) VALUES (?)', ['x']);
            $this->fail('expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertSame('blocked', $e->getMessage());
        }
        // BOTH interceptors ran, in registration order, before the throw — no last-writer-wins:
        $this->assertSame(['a', 'b'], $order);
    }

    public function test_no_interceptors_is_a_noop(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE t (id INTEGER PRIMARY KEY)');
        $executor = new QueryExecutor($pdo, new \Glueful\Database\Execution\ParameterBinder(), new \Glueful\Database\QueryLogger());
        $stmt = $executor->executeStatement('SELECT * FROM t', []);
        $this->assertSame([], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }
}
```

*(Adjust the `QueryExecutor` constructor args in the test to match its real signature from Step 1 if it differs.)*

- [ ] **Step 3: Run it — expect FAIL** (`addQueryInterceptorCallback` undefined).

Run: `vendor/bin/phpunit tests/Unit/Database/Execution/QueryInterceptorTest.php`
Expected: FAIL — `Call to undefined method ...::addQueryInterceptorCallback()`.

- [ ] **Step 4: Add the interface.** `framework/src/Database/Execution/QueryInterceptorInterface.php`:

```php
<?php
declare(strict_types=1);

namespace Glueful\Database\Execution;

/**
 * A pre-execution query interceptor. Invoked in QueryExecutor::executeStatement()
 * BEFORE the statement is prepared/executed. Throwing prevents the query from running.
 */
interface QueryInterceptorInterface
{
    /** @param array<int|string,mixed> $bindings */
    public function before(string $sql, array $bindings): void;
}
```

- [ ] **Step 5: Wire it into `QueryExecutor` as a *chainable* registry.** Multiple interceptors may register (e.g. tenancy guard + an unrelated audit hook); **all** run, in registration order — never last-writer-wins. Registration is process-level boot config; each interceptor carries no per-request state (it reads request-scoped context at call time).

```php
// in class QueryExecutor:
/** @var array<int,QueryInterceptorInterface> */
private static array $interceptors = [];

public static function addQueryInterceptor(QueryInterceptorInterface $i): void
{
    self::$interceptors[] = $i;
}

/** Convenience for closures (used by tests and simple hooks). */
public static function addQueryInterceptorCallback(callable $cb): void
{
    self::$interceptors[] = new class ($cb) implements QueryInterceptorInterface {
        /** @param callable $cb */
        public function __construct(private $cb) {}
        public function before(string $sql, array $bindings): void { ($this->cb)($sql, $bindings); }
    };
}

public static function clearQueryInterceptors(): void
{
    self::$interceptors = [];
}
```

In `executeStatement()`, as the **first lines** (before `prepare`):

```php
foreach (self::$interceptors as $interceptor) {
    $interceptor->before($sql, $bindings);
}
```

- [ ] **Step 6: Run — expect PASS.**

Run: `vendor/bin/phpunit tests/Unit/Database/Execution/QueryInterceptorTest.php`
Expected: PASS (2 tests).

- [ ] **Step 7: Full suite sanity** — `composer test` (no interceptors registered by default → no behavior change). Expected: green.

- [ ] **Step 8: Commit.**

```bash
git add src/Database/Execution/QueryInterceptorInterface.php src/Database/Execution/QueryExecutor.php tests/Unit/Database/Execution/QueryInterceptorTest.php
git commit -m "feat(db): add pre-execution query interceptor seam to QueryExecutor"
```

### Task 0.2: `Connection::table()` decorator seam

**Chainable hooks** applied to the `QueryBuilder` returned by `table()`, each receiving `(QueryBuilder $qb, string $table, Connection $conn)` — all run, in registration order, so extensions can't clobber each other. Lets an extension pre-apply a scope to registered tables.

**Files:**
- Modify: `framework/src/Database/Connection.php` (`table()` ~line 538)
- Test: `framework/tests/Unit/Database/ConnectionTableHookTest.php`

- [ ] **Step 1: Write the failing test.**

```php
<?php
namespace Glueful\Tests\Unit\Database;

use Glueful\Database\Connection;
use PHPUnit\Framework\TestCase;

final class ConnectionTableHookTest extends TestCase
{
    protected function tearDown(): void { Connection::clearTableHooks(); }

    public function test_all_table_hooks_run_in_registration_order(): void
    {
        $calls = [];
        Connection::addTableHook(function ($qb, string $table, $conn) use (&$calls) { $calls[] = "a:$table"; });
        Connection::addTableHook(function ($qb, string $table, $conn) use (&$calls) { $calls[] = "b:$table"; });
        // Use the test connection helper (SQLite memory) used elsewhere in the suite:
        $conn = new Connection(['engine' => 'sqlite', 'sqlite' => ['primary' => ':memory:']]);
        $conn->table('invoices');
        $this->assertSame(['a:invoices', 'b:invoices'], $calls); // both ran, in order — no last-writer-wins
    }
}
```

*(Match the `Connection` constructor/config shape to how the suite builds a SQLite connection — check an existing DB test, e.g. under `tests/` that news up a `Connection`.)*

- [ ] **Step 2: Run — expect FAIL** (`addTableHook` undefined).

Run: `vendor/bin/phpunit tests/Unit/Database/ConnectionTableHookTest.php`

- [ ] **Step 3: Implement chainable hooks in `Connection`.**

```php
/** @var array<int,\Closure(QueryBuilder, string, Connection):void> */
private static array $tableHooks = [];

public static function addTableHook(\Closure $hook): void
{
    self::$tableHooks[] = $hook;
}

public static function clearTableHooks(): void
{
    self::$tableHooks = [];
}

public function table(string $table): QueryBuilder
{
    $qb = $this->createQueryBuilder()->from($table);
    foreach (self::$tableHooks as $hook) {
        $hook($qb, $table, $this);
    }
    return $qb;
}
```

- [ ] **Step 4: Run — expect PASS**, then `composer test` (no hooks by default → no behavior change). Expected: green.

- [ ] **Step 5: Commit.**

```bash
git add src/Database/Connection.php tests/Unit/Database/ConnectionTableHookTest.php
git commit -m "feat(db): add Connection::table() decorator hook seam"
```

### Task 0.3: Phase 0 checkpoint

- [ ] Run `composer test && composer run phpcs` (or `composer ci`). Expected: green. **This is the PR boundary** — Phase 0 merges before the extension depends on it.

---

## Phase 1 — Extension skeleton + registry

> All Phase 1+ paths are under `/Users/michaeltawiahsowah/Sites/glueful/extensions/tenancy`. The extension's tests run via its own `phpunit.xml` against the framework as a path/dev dependency (mirror an existing extension like `glueful/archive` for the composer + phpunit + bootstrap setup).

### Task 1.1: Package skeleton + ServiceProvider

**Files:**
- Create: `composer.json`, `phpunit.xml`, `tests/bootstrap.php`, `config/tenancy.php`, `src/TenancyServiceProvider.php`
- Test: `tests/Unit/ServiceProviderTest.php`

- [ ] **Step 1:** Scaffold by copying the composer/phpunit/bootstrap shape from an existing extension (`extensions/archive`). Set `"name": "glueful/tenancy"`, PSR-4 `Glueful\\Extensions\\Tenancy\\` → `src/`, `extra.glueful.provider` → `Glueful\Extensions\Tenancy\TenancyServiceProvider`, require `glueful/framework` (dev path repo locally; `^1.53` for publish).

- [ ] **Step 2: Write the failing test** — the `tenant` middleware alias is a **container alias declared in `services()`** (the router resolves string middleware names through the container, and the container is compiled *before* `boot()`, so `boot()` is the wrong place).

```php
<?php
namespace Glueful\Extensions\Tenancy\Tests\Unit;

use Glueful\Extensions\Tenancy\TenancyServiceProvider;
use Glueful\Extensions\Tenancy\Http\TenantMiddleware;

final class ServiceProviderTest extends \PHPUnit\Framework\TestCase
{
    public function test_services_register_tenant_as_a_container_alias_of_TenantMiddleware(): void
    {
        $services = TenancyServiceProvider::services();
        $def = $services[TenantMiddleware::class] ?? null;
        $this->assertNotNull($def, 'TenantMiddleware must be defined in services()');
        $this->assertContains('tenant', (array) ($def['alias'] ?? []));
    }
}
```

- [ ] **Step 3: Run — FAIL.** `vendor/bin/phpunit tests/Unit/ServiceProviderTest.php`

- [ ] **Step 4: Implement `TenancyServiceProvider`** extending `Glueful\Extensions\ServiceProvider`.
  - **`services()`** returns the array DSL, including the middleware as a container service **with the alias** (this is the registration mechanism — the router resolves `'tenant'` via the container):
    ```php
    TenantMiddleware::class => ['class' => TenantMiddleware::class, 'shared' => true, 'autowire' => true, 'alias' => ['tenant']],
    ```
    plus `TenantContext`, `TenantResolutionPipeline`, `TenantTableRegistry`, `Tenancy`, etc.
  - **`register()`**: `mergeConfig('tenancy', …)`, `loadMigrationsFrom(__DIR__.'/../migrations', MigrationPriority::FOUNDATION)`, load routes if any.
  - **`boot()`**: register the chainable `Connection::addTableHook(...)` + `QueryExecutor::addQueryInterceptor(...)` (Phase 5), populate `TenantTableRegistry` from config (Phase 5.1), and `discoverCommands(...)`. **Do not** register the alias here.
  - Keep a static `middlewareAliases(): array` returning `['tenant' => TenantMiddleware::class]` for **docs/tests only** — it must not be the registration path.

- [ ] **Step 5: Write `config/tenancy.php`** exactly per the spec's Configuration section (resolvers ordered list; subdomain/path/header/query/jwt settings; `enforcement.required_by_default=true`; `enforcement.guard.dev=throw`/`prod=metric`; `bypass_permissions=['tenancy.access_any','tenancy.manage']`; `membership.roles`).

- [ ] **Step 6: Run — PASS. Commit.**

```bash
git add -A && git commit -m "feat: package skeleton, ServiceProvider, tenancy config"
```

### Task 1.2: Migrations — `tenants` + `tenant_memberships`

**Files:**
- Create: `migrations/001_CreateTenantsTable.php`, `migrations/002_CreateTenantMembershipsTable.php`
- Test: `tests/Integration/MigrationsTest.php`

- [ ] **Step 1: Write the failing test** — run both migrations on `:memory:` and assert columns/indexes exist; rollback cleanly.

```php
public function test_migrations_create_tenants_and_memberships(): void
{
    $schema = $this->schemaBuilder(); // helper: framework SchemaBuilder on :memory:
    (new CreateTenantsTable())->up($schema);
    (new CreateTenantMembershipsTable())->up($schema);
    $this->assertTrue($schema->hasTable('tenants'));
    $this->assertTrue($schema->hasTable('tenant_memberships'));
    $this->assertTrue($schema->hasColumn('tenants', 'uuid'));
    $this->assertTrue($schema->hasColumn('tenant_memberships', 'tenant_uuid'));
    $this->assertTrue($schema->hasColumn('tenant_memberships', 'user_uuid'));
}
```

- [ ] **Step 2: Run — FAIL.**

- [ ] **Step 3: Implement migrations** per the spec data model, following the `glueful-write-migration` conventions (implement `MigrationInterface` `up`/`down`/`getDescription`; guard with `hasTable`; explicit columns; `bigInteger('id')->primary()->autoIncrement()`, `string('uuid',12)`, `string('slug')` unique on tenants, `status`, `settings` text/json nullable, timestamps, `deleted_at`). On `tenant_memberships`: `string('tenant_uuid',12)` indexed + FK to `tenants(uuid)` (same package — allowed), `string('user_uuid',12)` indexed (**no FK**), `role`, `status`, timestamps, unique `(tenant_uuid, user_uuid)`. Filenames `001_`/`002_` for FK ordering.

- [ ] **Step 4: Run — PASS** (and verify `down()` rollback in the test). **Commit.**

```bash
git add migrations tests/Integration/MigrationsTest.php
git commit -m "feat: tenants + tenant_memberships migrations (FOUNDATION)"
```

### Task 1.3: `Tenant` + `TenantMembership` models (central — not tenant-scoped)

**Files:**
- Create: `src/Models/Tenant.php`, `src/Models/TenantMembership.php`
- Test: `tests/Integration/TenantModelTest.php`

- [ ] **Step 1: Failing test** — create a `Tenant`, find by uuid/slug; create a `TenantMembership`; query memberships for a user. Assert these models are **central** (no `tenant_uuid` scoping applied).

- [ ] **Step 2: Run — FAIL.**

- [ ] **Step 3: Implement** both as plain `Glueful\Database\ORM\Model` subclasses (table `tenants` / `tenant_memberships`, uuid key). **Do not** use `BelongsToTenant`. Add helpers: `Tenant::findBySlug()`, `Tenant::isActive()`, `TenantMembership` scopes `forUser($uuid)`, `active()`.

- [ ] **Step 4: PASS. Commit.**

```bash
git add src/Models tests/Integration/TenantModelTest.php
git commit -m "feat: Tenant and TenantMembership models"
```

### Task 1.4: `TenancyStrategyInterface` seam + `RowLevelStrategy`

The pluggable isolation seam; v1 ships only the row-level implementation, but the interface keeps a future hard-isolation adapter a drop-in.

**Files:** Create `src/Strategy/TenancyStrategyInterface.php`, `src/Strategy/RowLevelStrategy.php`; Test `tests/Unit/Strategy/RowLevelStrategyTest.php`.

- [ ] **Step 1: Failing test** — the container resolves `TenancyStrategyInterface` to a `RowLevelStrategy`, and `strategy()->name()` returns `'row-level'`. (Keep the interface minimal: `name(): string` plus a marker method the later phases can hang behavior on, e.g. `scopesViaColumn(): bool` returning `true` for row-level.)

- [ ] **Step 2: Run — FAIL.**

- [ ] **Step 3: Implement** the interface + `RowLevelStrategy`; bind `TenancyStrategyInterface::class => RowLevelStrategy::class` in `TenancyServiceProvider::services()` (`'alias'`).

- [ ] **Step 4: PASS. Commit** (`feat: TenancyStrategyInterface seam + RowLevelStrategy`).

---

## Phase 2 — Request-scoped TenantContext

### Task 2.1: `TenantContext` on `ApplicationContext::requestState`

**Files:**
- Create: `src/Context/TenantContext.php`
- Test: `tests/Integration/TenantContextTest.php`

- [ ] **Step 1: Failing test.**

```php
public function test_set_get_clear_current_tenant_is_request_scoped(): void
{
    $ctx = $this->appContext(); // framework ApplicationContext
    $tc = new TenantContext($ctx);
    $this->assertFalse($tc->hasTenant());

    $tenant = $this->makeActiveTenant('acme');
    $tc->setTenant($tenant);
    $this->assertTrue($tc->hasTenant());
    $this->assertSame($tenant->uuid, $tc->currentTenantUuid());
    // stored under requestState, not statics:
    $this->assertSame($tenant->uuid, $ctx->getRequestState('tenancy.tenant')->uuid);

    $tc->clear();
    $this->assertFalse($tc->hasTenant());
    // clear() only touched tenancy keys:
    $this->assertNull($ctx->getRequestState('tenancy.tenant'));
}
```

- [ ] **Step 2: Run — FAIL.**

- [ ] **Step 3: Implement `TenantContext`**: constructor takes `ApplicationContext`. `setTenant(Tenant)` → `ctx->setRequestState('tenancy.tenant', $tenant)`. `currentTenant(): ?Tenant`, `currentTenantUuid(): ?string`, `hasTenant(): bool` read from `getRequestState('tenancy.tenant')`. `clear()` → `setRequestState('tenancy.tenant', null)` and `setRequestState('tenancy.bypass', null)` — **never** `resetRequestState()`. Add `setBypass(string $mode)` / `bypassMode(): ?string` under `tenancy.bypass` (used by Phase 6).

- [ ] **Step 4: PASS. Commit.**

```bash
git add src/Context/TenantContext.php tests/Integration/TenantContextTest.php
git commit -m "feat: request-scoped TenantContext on ApplicationContext::requestState"
```

---

## Phase 3 — Validated resolution + middleware

### Task 3.1: `TenantResolverInterface` + built-in resolvers

**Files:**
- Create: `src/Resolution/TenantResolverInterface.php` and `src/Resolution/Resolvers/{Header,Query,Subdomain,Path,JwtClaim,ActiveSession}Resolver.php`
- Test: `tests/Unit/Resolution/ResolversTest.php`

- [ ] **Step 1: Interface.**

```php
interface TenantResolverInterface
{
    /** @return string|null candidate tenant uuid OR slug */
    public function resolve(\Symfony\Component\HttpFoundation\Request $request, \Glueful\Bootstrap\ApplicationContext $context): ?string;
}
```

- [ ] **Step 2: Failing tests** — one per resolver, each asserting the candidate it extracts and `null` when absent:
  - `HeaderResolver` → `X-Tenant-Id` (config-named).
  - `QueryResolver` → `?tenant_id`.
  - `SubdomainResolver` → first host label below `base_domain` (`acme.app.com` → `acme`); `null` when host == base domain.
  - `PathResolver` → segment after the configured prefix (`/t/acme/...` → `acme`).
  - `JwtClaimResolver` → the configured claim from the request's JWT claims attribute.
  - `ActiveSessionResolver` → the user's selected active tenant from session/token attribute.

  Write all six concrete tests (distinct inputs/expected values — do not abbreviate).

- [ ] **Step 3: Run — FAIL.**

- [ ] **Step 4: Implement all six resolvers** (each reads from `Request` + config). Keep each ~15–30 lines, single responsibility.

- [ ] **Step 5: PASS. Commit** (`feat: tenant resolver interface + 6 built-in resolvers`).

### Task 3.2: `ResolverChain` (ordered, first non-null wins)

**Files:** Create `src/Resolution/ResolverChain.php`; Test `tests/Unit/Resolution/ResolverChainTest.php`.

- [ ] Failing test: a chain `[A→null, B→'acme', C→'other']` returns `'acme'` (first non-null); empty chain → `null`. Implement; PASS; commit.

### Task 3.3: `TenantResolutionPipeline` (validate → set context / fail closed)

**Files:** Create `src/Resolution/TenantResolutionPipeline.php`, `src/Exceptions/{TenantNotFoundException,TenantAccessDeniedException}.php`; Test `tests/Integration/ResolutionPipelineTest.php`.

- [ ] **Step 1: Failing tests** (concrete branches):
  - candidate resolves to an **active** tenant + authenticated **member** → `TenantContext` set to that tenant.
  - candidate tenant **inactive/nonexistent** → throws `TenantNotFoundException` (maps to **404**).
  - active tenant, authenticated user **not a member** and **no bypass permission** → throws `TenantAccessDeniedException` (maps to **403**).
  - active tenant, user holds a config bypass permission (stub the Gate to grant `tenancy.access_any`) → allowed, context set, bypass mode `forAnyTenant`.
  - tenant-required route, **no candidate** → `TenantNotFoundException` (404).
  - unauthenticated + central/public route (tenant optional) → context left empty, no throw.

- [ ] **Step 2: Run — FAIL.**

- [ ] **Step 3: Implement the pipeline**: `resolve(Request, $context, bool $required): void`. Steps: run `ResolverChain` → candidate; if none and `$required` → throw `TenantNotFoundException`; look up `Tenant` (by uuid then slug) requiring `status=active`, else `TenantNotFoundException`; if a user is authenticated, check bypass permission via `TenantAccess` (Phase 6) — if granted, set bypass mode; else require active `TenantMembership`, else `TenantAccessDeniedException`; finally `TenantContext::setTenant()`. Map the two exceptions to 404/403 in the middleware (Task 3.4). Add a config flag read to collapse 403→404 when existence-hiding is on.

- [ ] **Step 4: PASS. Commit** (`feat: validated tenant resolution pipeline (404/403, membership, bypass)`).

### Task 3.4: `TenantMiddleware` (alias `tenant`, after auth, try/finally)

**Files:** Create `src/Http/TenantMiddleware.php`; Test `tests/Integration/TenantMiddlewareTest.php`.

- [ ] **Step 1: Failing test** — build a `Router`, register a route with `->middleware(['tenant'])`, dispatch:
  - valid tenant + member → handler runs with `TenantContext` set; **after** dispatch the tenancy request-state keys are cleared.
  - inactive tenant → `404` response.
  - member-less user → `403` response.

  (Use the framework `TestCase` + `actingWithPermissions`/membership fixtures; dispatch via `new Router($this->getContainer())` per `advanced/testing`.)

- [ ] **Step 2: Run — FAIL.**

- [ ] **Step 3: Implement** `TenantMiddleware implements RouteMiddleware`: constructor injects `TenantResolutionPipeline` + `TenantContext`; in `handle(Request,$next,...$params)` run the pipeline (required unless a `optional` param is passed), then `try { return $next($request); } finally { $this->tenantContext->clear(); }`. Catch `TenantNotFoundException`→404, `TenantAccessDeniedException`→403, returning the framework JSON error envelope. The `tenant` alias is **already declared in `services()`** (Task 1.1) — here, only **verify** that a route with `->middleware(['tenant'])` resolves `TenantMiddleware` through the container (do **not** re-register the alias in `boot()`).

- [ ] **Step 4: PASS. Commit** (`feat: tenant middleware (alias 'tenant', after-auth, fail-closed, context cleanup)`).

---

## Phase 4 — Automatic ORM scoping

> Mirror the existing `SoftDeletes` pattern. **Read first:** `framework/src/Database/ORM/Concerns/SoftDeletes.php`, `Scopes/SoftDeletingScope.php`, `Contracts/Scope.php`, `Concerns/HasGlobalScopes.php`, and `Model::bootTraits()` to copy the exact `Scope` method signature and the `bootX`/`initializeX` trait-boot convention.

### Task 4.1: `TenantScope implements Scope`

**Files:** Create `src/ORM/Scopes/TenantScope.php`, `src/Exceptions/MissingTenantContextException.php`; Test `tests/Integration/TenantScopeTest.php`.

- [ ] **Step 1: Read** `SoftDeletingScope.php` + `Contracts/Scope.php` to get the exact `apply(Builder $builder, Model $model): void` signature (and any `extend()` hook for builder macros).

- [ ] **Step 2: Failing test** — a model using the scope, with a current tenant set, produces SQL containing `where tenant_uuid = ?` bound to the current uuid; with **no** tenant and the model tenant-required, querying throws `MissingTenantContextException`.

- [ ] **Step 3: Implement `TenantScope`**: in `apply(Builder $builder, Model $model)`, resolve context from the **model's own context** — `$ctx = $model->getContext()` (confirmed: `Model::getContext()` at `Model.php:196`), then read the tenant via `$ctx?->getRequestState('tenancy.tenant')` and the bypass mode via `$ctx?->getRequestState('tenancy.bypass')`. **Never read an ambient/static global** — this keeps scoping request-scoped and unit-testable.
  - bypass active (`forAnyTenant`/`runAsSystem`) → add no predicate.
  - tenant present → `$builder->where($model->getTable().'.tenant_uuid', $tenant->uuid)`.
  - model is tenant-required AND (no context OR no tenant) AND no bypass → throw `MissingTenantContextException` (fail closed).

- [ ] **Step 4: PASS. Commit** (`feat: TenantScope global scope (fail-closed, bypass-aware)`).

### Task 4.2: `BelongsToTenant` trait

**Files:** Create `src/ORM/Concerns/BelongsToTenant.php`; Test `tests/Integration/BelongsToTenantTest.php`.

- [ ] **Step 1: Failing tests** using a test model `Project` (table `projects(uuid, tenant_uuid, name)`):
  - reads are scoped to the current tenant (tenant A sees only A's rows; `find(B-uuid)` under A → null).
  - `creating` stamps `tenant_uuid` from `TenantContext` when absent.
  - `creating` with **no** current tenant → throws `MissingTenantContextException`.
  - `updating` a row's `tenant_uuid` → throws (immutable).
  - the model's table is auto-registered in `TenantTableRegistry`.

- [ ] **Step 2: Run — FAIL.**

- [ ] **Step 3: Implement `BelongsToTenant`**: `bootBelongsToTenant()` → `static::addGlobalScope(new TenantScope())`; register `static::creating(fn($m) => …stamp tenant_uuid or throw…)`; register `static::updating(fn($m) => …reject tenant_uuid change…)` (use the model lifecycle hooks per `HasEvents`); register the table in `TenantTableRegistry::register(static::tableName())`. `initializeBelongsToTenant()` → ensure `tenant_uuid` is fillable/guarded appropriately. (Match the exact event-registration API from `HasEvents`.)

- [ ] **Step 4: PASS. Commit** (`feat: BelongsToTenant trait (scoped reads, stamped+immutable writes, registry)`).

### Task 4.3: Builder macros `withoutTenantScope()` / `forAnyTenant()`

**Files:** Modify `src/ORM/Scopes/TenantScope.php` (implement the scope's `extend(Builder)` to add macros) **or** add via `Contracts/ExtendsBuilder`; Test `tests/Integration/TenantScopeMacrosTest.php`.

- [ ] Failing test: `Project::withoutTenantScope()->get()` returns rows across tenants; `Project::forAnyTenant()->get()` same but flagged as an audited bypass. Implement the macros by removing the `TenantScope` from the builder (mirror `withTrashed()` in `SoftDeletingScope::extend()`). **Noisy names only** — do not add a generic `withoutScope()`. PASS; commit.

---

## Phase 5 — Non-ORM enforcement

### Task 5.1: `TenantTableRegistry` (config is the authoritative source)

**Files:** Create `src/Query/TenantTableRegistry.php`; Modify `config/tenancy.php` (add `tables`) + `TenancyServiceProvider::boot()`; Test `tests/Unit/Query/TenantTableRegistryTest.php`, `tests/Integration/RegistryBeforeBootTest.php`.

**Why config-first:** `BelongsToTenant::boot()` only registers a table *after* that model class is first touched. A raw query to `projects` *before* `Project` has ever booted would miss both auto-injection and the guard. So the **authoritative** source is an explicit list — `config('tenancy.tables', [])` (e.g. `['projects', 'invoices']`) loaded in the provider's `boot()` *before any request runs queries* — plus `Tenancy::registerTable()` for programmatic additions. Trait boot (Task 4.2) is a **convenience backstop**, never the sole source.

- [ ] **Step 1: Add `tables` to `config/tenancy.php`** — an array of tenant-owned table names (authoritative). Document that every `BelongsToTenant` model's table should be listed here.

- [ ] **Step 2: Failing unit test** — `register('invoices')`, `register('projects')`; `isTenantOwned('invoices')` true, `isTenantOwned('users')` false; `all()` returns the set; `clear()` empties it.

- [ ] **Step 3: Failing integration test (`RegistryBeforeBootTest`)** — boot the provider with `config(['tenancy.tables' => ['widgets']])` and assert `TenantTableRegistry::isTenantOwned('widgets')` is **true without ever referencing the `Widget` model class** (proving config population, not model-boot population). *(Scope only: this task asserts the **registry** is populated pre-boot. The downstream behavior — that a raw `db()->table('widgets')` is then auto-scoped (5.2) and an unscoped raw query is guarded (5.3) — is asserted in those tasks, which implement it.)*

- [ ] **Step 4: Implement** the registry (a process-level set: `register`/`isTenantOwned`/`all`/`clear`) and populate it from `config('tenancy.tables', [])` in `TenancyServiceProvider::boot()`. Have `BelongsToTenant::bootBelongsToTenant()` *also* call `TenantTableRegistry::register(static::tableName())` as a backstop.

- [ ] **Step 5: PASS both. Commit** (`feat: TenantTableRegistry (config-authoritative + trait backstop)`).

### Task 5.2: Auto-injection via the `Connection::table()` hook (seam #1)

**Files:** Create `src/Query/TenantQuery.php` (helpers) + boot wiring in `TenancyServiceProvider`; Test `tests/Integration/AutoInjectionTest.php`.

- [ ] **Step 1: Failing test** — with a current tenant set and `invoices` registered: `db($ctx)->table('invoices')->get()` produces SQL with `where tenant_uuid = ?` and returns only the current tenant's rows; an **unregistered** table is untouched; under `runAsSystem` no predicate is injected; and a table registered **only via config** (no model class ever loaded — moved from Task 5.1) is auto-scoped too.

- [ ] **Step 2: Run — FAIL.**

- [ ] **Step 3: Implement** in `boot()`: `Connection::addTableHook(function ($qb, $table, $conn) { if (TenantTableRegistry::isTenantOwned($table) && !bypassActive() && hasCurrentTenant()) { $qb->where("$table.tenant_uuid", currentTenantUuid()); } });`. Add `TenantQuery::scope($qb, $ctx)` and a **`TenantQuery::tenantTable($ctx, $name)`** static helper — a thin wrapper that calls `db($ctx)->table($name)` (already auto-scoped by the hook) and asserts the table is registered. **Not a `db()->tenantTable()` method:** the Phase 0 hook only decorates `Connection::table()`, it does not add methods to `Connection`, so no third core seam is needed. Resolve the current tenant from the request-scoped `TenantContext`, never a static. (Uses the **chainable** `addTableHook` seam from Task 0.2, so a host app's own table hooks still run.)

- [ ] **Step 4: PASS. Commit** (`feat: primary-table auto-injection via Connection table hook`).

### Task 5.3: `TenantQueryGuard` interceptor (seam #2) — dev/test throw, prod metric

**Files:** Create `src/Query/TenantQueryGuard.php`, `src/Exceptions/TenantScopeViolationException.php`; boot wiring; Test `tests/Integration/TenantQueryGuardTest.php`.

- [ ] **Step 1: Failing tests** (env-gated):
  - with `APP_ENV=testing`, a deliberately unscoped raw query against a registered table (`$conn->query()->...` bypassing `table()`, or a raw `selectRaw`) → throws `TenantScopeViolationException`.
  - the same query wrapped in `runAsSystem`/`forAnyTenant` → no throw.
  - a scoped query (`...where tenant_uuid = ?`) → no throw.
  - in `prod` mode, an unscoped query → **no throw**, but a `tenancy.unscoped_query` metric/log is emitted (assert via a spy logger/metrics stub).

- [ ] **Step 2: Run — FAIL.**

- [ ] **Step 3: Implement `TenantQueryGuard implements QueryInterceptorInterface`**: `before($sql, $bindings)` → parse the SQL for registered tenant-owned table references lacking a `tenant_uuid` predicate (a conservative regex/tokenizer: detect `from|join|into|update <registered>` without a `tenant_uuid` comparison for that table); if a violation and no bypass active: dev/test → throw; prod → emit metric/log per `config('tenancy.enforcement.guard.prod')`. Register via `QueryExecutor::addQueryInterceptor(new TenantQueryGuard(...))` in `boot()` (guarded by `config('tenancy.enabled')`; chainable seam from Task 0.1, so other interceptors still run). Keep detection conservative (false-negatives over false-positives in prod; dev/test surfaces the rest).

- [ ] **Step 4: PASS. Commit** (`feat: TenantQueryGuard pre-execution interceptor (dev-throw/prod-metric)`).

---

## Phase 6 — Bypass APIs + authorization

### Task 6.1: `Tenancy` facade + `TenantAccess` (Gate-permission bypass)

**Files:** Create `src/Bypass/Tenancy.php`, `src/Authorization/TenantAccess.php`; Test `tests/Integration/TenancyBypassTest.php`.

- [ ] **Step 1: Failing tests:**
  - `Tenancy::runAsTenant($tenant, fn)` sets context to `$tenant` for the closure and restores prior context after (try/finally), even on exception.
  - `Tenancy::runAsSystem(fn)` runs with no tenant + suspends scope/guard for the closure.
  - `Tenancy::forAnyTenant(fn)` on a request path **requires** a config bypass permission via the Gate — denied user → throws/`403`; permitted user → runs cross-tenant.
  - `TenantAccess::canBypass($userUuid)` returns true iff the user holds any `config('tenancy.bypass_permissions')` permission (stub the Gate).
  - `Tenancy::registerTable('audit_logs')` adds to the registry.

- [ ] **Step 2: Run — FAIL.**

- [ ] **Step 3: Implement.** `Tenancy` resolves `TenantContext` from the container; `runAsTenant`/`runAsSystem`/`forAnyTenant` save→set→`try/finally`→restore the prior tenant + bypass mode (request-scoped). `TenantAccess::canBypass()` calls the Gate (`Glueful\Permissions\Gate`) checking the config bypass permissions with tenant context. `forAnyTenant` on request paths calls `TenantAccess::canBypass()` and throws `TenantAccessDeniedException` if not permitted (system/CLI context may pass a flag to skip the check). All names are tenancy-specific (no generic `withoutScope`).

- [ ] **Step 4: PASS. Commit** (`feat: noisy bypass APIs + Gate-permission bypass (TenantAccess)`).

---

## Phase 7 — Context propagation (jobs / CLI / scheduler)

### Task 7.1: `PropagatesTenant` queue job-middleware

**Files:** Create `src/Queue/PropagatesTenant.php`; Test `tests/Integration/QueueTenantPropagationTest.php`.

- [ ] **Step 1: Failing test** — dispatch a job inside a tenant-A request (set `TenantContext` to A): the serialized job payload carries `tenant_uuid=A`. Then simulate the worker (fresh context, no tenant): running the job through `PropagatesTenant` restores `TenantContext`=A (re-validated active) for the duration of `handle()`, then clears it; an archived/inactive tenant → job fails fast with a clear error.

- [ ] **Step 2: Run — FAIL.**

- [ ] **Step 3: Implement** the capture (read current tenant via `QueueContextHolder::getContext()` → `TenantContext`, write `tenant_uuid` into the job payload/metadata at dispatch) and the restore (worker reads it, sets `TenantContext`, re-validates `status=active`, `try/finally` clears). Wire as a queue middleware / job lifecycle hook per the framework's queue extension points.

- [ ] **Step 4: PASS. Commit** (`feat: per-job tenant context propagation (PropagatesTenant)`).

### Task 7.2: CLI `--tenant` + `tenant:run` + scheduler helper

**Files:** Create `src/Console/Concerns/RunsInTenantContext.php` (a `--tenant` option + context set), `src/Scheduling/ForEachTenant.php`; Test `tests/Integration/CliTenantContextTest.php`.

- [ ] Failing test: a command using the concern with `--tenant=acme` runs with `TenantContext`=acme; without it, runs in system context. `ForEachTenant::run(fn)` iterates active tenants, setting context per run (assert each active tenant's context was set exactly once, archived ones skipped). Implement; PASS; commit.

---

## Phase 8 — Console commands

### Task 8.1: `tenant:create|list|activate|suspend|diagnose`

**Files:** Create `src/Console/{CreateTenant,ListTenants,ActivateTenant,SuspendTenant,DiagnoseTenancy}Command.php`; Test `tests/Integration/ConsoleCommandsTest.php`.

- [ ] **Step 1: Failing tests** (one assertion-focused test per command):
  - `tenant:create --slug=acme --name="Acme"` inserts an active tenant; duplicate slug → error.
  - `tenant:list` outputs existing tenants with status.
  - `tenant:activate <slug>` / `tenant:suspend <slug>` flip `status`.
  - `tenant:diagnose` reports: (a) models using `BelongsToTenant` whose table lacks a `tenant_uuid` column (drift), (b) registered tenant-owned tables, (c) membership integrity (memberships pointing at nonexistent tenants). Assert it flags a seeded drift case.

- [ ] **Step 2: Run — FAIL.**

- [ ] **Step 3: Implement** the five commands as `#[AsCommand]` classes extending the framework `BaseCommand`; discover them via `discoverCommands()` in `boot()`.

- [ ] **Step 4: PASS. Commit** (`feat: tenant console commands (create/list/activate/suspend/diagnose)`).

---

## Phase 9 — End-to-end isolation tests + docs

### Task 9.1: Cross-tenant isolation integration test (the security contract)

**Files:** Create `tests/Integration/CrossTenantIsolationTest.php`.

- [ ] **Step 1: Write one comprehensive test class** asserting, with seeded tenants A and B each owning `Project` rows:
  - acting as an A-member, `Project::all()` returns only A's rows; `Project::find(bProjectUuid)` is `null`; `update`/`delete` of a B row is impossible (scope hides it).
  - creating a `Project` as A stamps `tenant_uuid=A`; tampering `tenant_uuid` on update throws.
  - a raw unscoped `db()->query()->...` against `projects` throws `TenantScopeViolationException` in the test env.
  - a job dispatched as A restores A's context on the worker.
  - `Tenancy::forAnyTenant()` with the bypass permission can read across A and B; without it, denied.
  - resolving an inactive tenant → 404; non-member → 403.

- [ ] **Step 2: Run — expect all PASS** (this is the acceptance gate for the extension). Fix any leak surfaced.

- [ ] **Step 3: Commit** (`test: cross-tenant isolation acceptance suite`).

### Task 9.2: Docs (README + usage)

**Files:** Create `README.md`.

- [ ] Document: install/enable, the three table classes, adding `BelongsToTenant` + a `tenant_uuid` migration column (with the **composite-unique `(tenant_uuid, slug)`** pitfall called out), the `tenant` middleware, the resolver config, the **noisy bypass APIs** and when each is appropriate, the **security posture** (logical not hard isolation), and the non-ORM rule (use models or `tenantTable()`; the guard catches the rest). Commit (`docs: tenancy usage + security posture`).

### Task 9.3: Final checkpoint

- [ ] Extension test suite green; `composer run analyse`/`phpcs` clean. Enable the extension in a scratch api-skeleton, run `migrate:run`, create a tenant, exercise a tenant-scoped endpoint, and confirm `tenant:diagnose` is clean. Tag-ready.

---

## Self-review notes (coverage map)

- Spec Pillar 1 → Tasks 1.2, 1.3. Pillar 2 → 3.1–3.4. Pillar 3 → 4.1–4.3. Pillar 4 (auto-injection + guard + registry) → Phase 0 (seams) + 5.1–5.3. Context propagation → 7.1–7.2. Strategy seam → Task 1.4 (`TenancyStrategyInterface` + `RowLevelStrategy`); the concrete row-level behavior lives across Phases 4–5. Bypass/authorization → 6.1. Console → 8.1. Security contract → 9.1. Required core seams → 0.1, 0.2.
- Naming consistency: `tenant_uuid`, `TenantContext`, `TenantScope`, `BelongsToTenant`, `TenantQueryGuard`, `TenantResolutionPipeline`, `Tenancy::{runAsTenant,runAsSystem,forAnyTenant}`, `withoutTenantScope()` used consistently across tasks; no generic `withoutScope()`.
- Open implementation detail deferred to execution (not a placeholder — grounded in named files to read): the exact `Scope::apply()` / builder-macro signatures (Task 4.1 Step 1 reads `SoftDeletingScope`), the model event-hook API (Task 4.2 reads `HasEvents`), and the queue middleware extension point (Task 7.1). These are "match the existing pattern" steps, not unspecified behavior.
