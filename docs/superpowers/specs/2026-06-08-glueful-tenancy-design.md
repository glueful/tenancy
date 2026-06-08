# glueful/tenancy — Design Spec

**Date:** 2026-06-08
**Status:** Draft for review
**Type:** New extension (`glueful/tenancy`)

## Summary

A packaged Glueful extension that adds **shared-database, row-level (table-relationship) multi-tenancy**: a `tenants` registry, `tenant_memberships`, automatic ORM scoping (`WHERE tenant_uuid = :current`), validated tenant resolution, and enforcement that extends to non-ORM query paths. It reuses the framework's existing ORM global-scope mechanism and partial tenant plumbing, and depends on **two small additive core seams** — a query-builder factory hook and a pre-execution query interceptor — introduced as a one-time core prerequisite for prod-grade non-ORM enforcement (see Pillar 4 → *Required core seams*). Everything else is pure extension.

> **Revision note (2026-06-08):** updated after technical review to (a) honestly name the required core hooks rather than claim "no core changes," (b) finalize `TenantContext` storage on `ApplicationContext::requestState`, (c) correct the migration-priority-per-directory detail, (d) clarify `QueueContextHolder`'s actual role, and (e) lock response codes (404 inactive/nonexistent tenant, 403 known-tenant-non-member).

## Goals

- Portable logical tenancy that works identically on MySQL/MariaDB/PostgreSQL/SQLite — no schema/DB-per-tenant assumptions in v1.
- **Default model: many tenants per user** (one-tenant is the single-membership degenerate case).
- Make **data scoping boring and strict**; make **tenant resolution pluggable**.
- Strong application-level isolation when used consistently, with safety nets that surface leaks in dev/test and detect drift in prod.

## Non-Goals (v1)

- **Hard isolation** (schema-per-tenant / database-per-tenant). The `TenancyStrategyInterface` seam is included so such an adapter can drop in later, but no adapter is built in v1.
- A per-tenant permission/RBAC engine. Authorization defers to the Gate / `glueful/aegis` with tenant context.
- Per-tenant migrations (shared DB ⇒ tenant-owned tables migrate once, globally).

## Positioning / Security Posture

This is **logical tenancy, not hard isolation.** All tenants share one database; isolation is enforced in the application. A single unguarded SQL path or query bug can cross tenants. The design mitigates this with (a) automatic ORM scoping, (b) automatic primary-table scoping in the query builder, and (c) a **query guard** — a *pre-execution interceptor* that **throws in dev/test** (prevention) and *post-execution monitoring* that **emits metrics/logs in prod** (detection) — when a registered tenant-owned table is queried without a tenant predicate. These reduce — but do not eliminate — the inherent risk of single-DB tenancy. Teams needing hard isolation are a future `TenancyStrategyInterface` adapter.

## Locked Decisions

1. **Many tenants per user is the default.** `tenant_memberships` is a core table, not optional. The simple one-tenant case is supported via helper APIs over the same model.
2. **`tenant_uuid` everywhere** (Glueful-style UUID string, `string(12)`), never `tenant_id`/bigint, to avoid ambiguity and match `user_uuid`.
3. **Privileged bypass via Gate permission(s), config-listed** (e.g. `tenancy.access_any`, `tenancy.manage`). **No hardcoded roles** — keeps Aegis / any policy provider in control.
4. **Strict guard via a query guard/interceptor.** Dev/test uses a **pre-execution interceptor that throws** when a registered tenant-owned table is queried without a tenant predicate (prevention). Prod **does not throw**; it uses **post-execution monitoring** to emit metrics/logs so teams detect drift without taking down traffic. SQL inspection is an accepted v1 safety net because it catches non-ORM paths; repository-layer scoping alone is too easy to bypass.
5. **Membership `role` stays thin** — a coarse label (`owner|admin|member|viewer`). No second permission engine; authorization decisions go through Gate/Aegis with tenant context.
6. **`BelongsToTenant` fails closed.** Tenant-owned models require a current tenant by default; no tenant ⇒ exception, never an unscoped query. Bypass must be **explicit** and **permission-gated on request paths**.
7. **Bypass APIs are noisy and tenancy-specific in name** — never a generic `withoutScope()`. Use names a reviewer spots instantly: `withoutTenantScope()`, `forAnyTenant()`, `Tenancy::runAsTenant()`, `Tenancy::runAsSystem()`.
8. **Tenant context is request-scoped, never static global.** Stored under `ApplicationContext::requestState` via `setRequestState('tenancy.tenant', …)` and read via `getRequestState('tenancy.tenant')` (the API already exists). `TenantMiddleware`'s `try/finally` clears **only the tenancy keys** (sets them null) — never `resetRequestState()`, which would wipe unrelated request state. This explicitly avoids the process-global state class of bug (cf. `SoftDeleteHandler`'s process-global column cache).

## Architecture — Four Pillars + Context Propagation + Strategy Seam

### Pillar 1 — Tenant registry & memberships

**`tenants`** (central; never scoped). Migration priority **FOUNDATION**.

| Column | Type | Notes |
|---|---|---|
| `id` | bigint, auto | PK |
| `uuid` | string(12) | unique; the `tenant_uuid` referenced everywhere |
| `slug` | string | unique; drives subdomain/path resolution |
| `name` | string | |
| `status` | string | `active \| suspended \| archived` |
| `settings` | json, null | per-tenant config blob |
| `created_at`/`updated_at`/`deleted_at` | timestamps | soft-deletable |

**`tenant_memberships`** (bridge global principals → tenants). Lives in the **same migration directory and priority as `tenants` (FOUNDATION)** — `loadMigrationsFrom()` takes one priority per directory, so a single registration covers both. Ordering is by filename (`002` after `001`), which satisfies the `tenant_uuid → tenants` FK. No `DEPENDENT` priority is needed: there is **no FK into the user store** (only `user_uuid`).

| Column | Type | Notes |
|---|---|---|
| `id` | bigint, auto | PK |
| `uuid` | string(12) | unique |
| `tenant_uuid` | string(12) | indexed; **FK to `tenants`** (same package — allowed) |
| `user_uuid` | string(12) | indexed; **no FK** (cross-package); resolve via `UserProviderInterface` |
| `role` | string | thin label: `owner\|admin\|member\|viewer` |
| `status` | string | `active \| invited \| suspended` |
| `created_at`/`updated_at` | timestamps | |

Unique constraint: `(tenant_uuid, user_uuid)`.

**Models:** `Tenant`, `TenantMembership` (both central — they do **not** use `BelongsToTenant`).

### Pillar 2 — Validated tenant resolution

Resolver output is **never trusted** until tenant activity and membership are verified.

- **`TenantResolverInterface::resolve(Request, ApplicationContext): ?string`** — returns a candidate `tenant_uuid` or `slug`.
- **Built-in resolvers** (ordered via config, first non-null wins): `SubdomainResolver`, `PathResolver`, `HeaderResolver` (`X-Tenant-Id`), `QueryResolver` (`?tenant_id`), `JwtClaimResolver`, `ActiveSessionResolver` (the user's selected active tenant).
- **`TenantResolutionPipeline`** (the authoritative path):
  1. Run resolver chain → candidate.
  2. Resolve to a `Tenant`; require it **exists** and `status = active`, else **404** (nonexistent/inactive look identical to a client).
  3. If a user is authenticated:
     - if the user holds a **config-listed bypass permission** (checked via the Gate) → allow as a cross-tenant principal (`forAnyTenant` posture);
     - else require an **active membership** `(tenant_uuid, user_uuid, status=active)`, else **403** (known tenant, not a member).
  4. Set `TenantContext` under `ApplicationContext::requestState`.
  5. A **tenant-required** route with no valid candidate ⇒ **404**.

  Response codes: **404** for nonexistent/inactive tenant; **403** for a known tenant the user isn't a member of. A config flag can collapse 403→404 for teams that want to hide tenant existence, but 403-on-membership is the default (clearer for API clients).
- **`TenantMiddleware`** (alias **`tenant`**) runs the pipeline, placed **after auth** (so membership/permission checks are possible), and wraps the downstream in `try/finally` to clear `TenantContext`.
- The framework's existing soft `tenant.id` attribute (`AuthToRequestAttributesMiddleware`) is treated as **one untrusted resolver input only**; this extension is the source of truth. No core change needed.

### Pillar 3 — Automatic ORM scoping

Mirrors the existing `SoftDeletes` + `SoftDeletingScope` pattern (`Concerns/HasGlobalScopes`, `Contracts/Scope`, `Builder::applyScopes()`).

- **`BelongsToTenant` trait** on tenant-owned models:
  - `bootBelongsToTenant()` registers `TenantScope`; a `creating` hook **stamps `tenant_uuid`** from `TenantContext` if absent; an `updating` hook **rejects `tenant_uuid` changes** (immutable).
  - Also registers the model's table in the `TenantTableRegistry` (Pillar 4).
- **`TenantScope implements Scope`**: injects `WHERE tenant_uuid = :current`. If the model is tenant-required and **no tenant is present → throws** (`MissingTenantContextException`) — fails closed, never returns an unscoped result set. Applies to relationship queries too (they route through `Builder::applyScopes()`).
- **Builder extensions (noisy names):** `withoutTenantScope()`, `forAnyTenant()` — the only ways to read across tenants from a model, both grep-visible in review.

### Pillar 4 — Non-ORM enforcement (auto-injection + guard)

More than documentation: two layers.

- **`TenantTableRegistry`** — the set of tenant-owned table names (auto-populated from `BelongsToTenant` models + explicit `Tenancy::registerTable()` for non-model tables).
- **Auto-injection:** when a builder is created for a registered tenant-owned table **as its primary table** (`db($ctx)->table('invoices')…`), it is pre-applied with `WHERE tenant_uuid = :current`. *This requires core seam #1* — `Connection::createQueryBuilder()` is `private` today, so an extension cannot decorate `table()` without it.
  - **Honest limitation:** auto-injection reliably covers the **primary table** and ORM relationships. It **cannot** rewrite joins-to-registered-tables, `selectRaw`, unions, or raw SQL strings. Those rely on the guard + explicit helper.
- **`TenantQueryGuard`:** inspects compiled SQL; if it references a registered tenant-owned table **without** a `tenant_uuid` predicate, and no bypass is active:
  - **dev/test → throw** `TenantScopeViolationException`. To *prevent* the access — critically, an unscoped `UPDATE`/`DELETE` — this must fire **before** `$stmt->execute()` (*core seam #2*). The framework's existing query event fires **after** execute(), which is detection-only and too late to stop a write.
  - **prod → emit a metric + warning log** (`tenancy.unscoped_query`), do **not** throw. The prod metric path can ride the existing **post-execution** query event and needs no new hook.
- **Explicit helper for legit raw access:** `TenantQuery::tenantTable($ctx, 'invoices')` / `TenantQuery::scope($builder, $ctx)` — a static helper that calls `table()` (already auto-scoped by seam #1) and asserts the table is registered; it adds **no** method to `Connection`.
- The guard and auto-injection are **bypass-aware** — suspended under `forAnyTenant`/`runAsSystem`.

#### Required core seams (one-time core prerequisite)

The extension is otherwise self-contained, but prod-grade non-ORM enforcement needs **two small, additive, generic hooks in core** — no behavior change, just extensibility:

1. **Query-builder factory seam** — make the builder behind `Connection::table()` overridable/decoratable (expose a `QueryBuilderFactory` binding, or make `createQueryBuilder()` `protected`/hookable). Enables auto-injection and `tenantTable()`.
2. **Pre-execution query interceptor** — a hook fired **before** `$stmt->execute()` that may throw, so the dev/test guard *prevents* rather than merely detects (essential for writes).

Both are generic seams useful beyond tenancy. They ship as a **small core PR that is an explicit prerequisite of this extension's implementation plan** (the plan sequences the core PR first).

**Fallback if the core PR slips:** auto-injection downgrades to the **explicit `tenantTable()` helper only**, and the guard runs **detection-only** on the existing post-execution event — dev/test throws still surface *read* leaks as test failures, but unscoped *writes* are detected after the fact, not prevented. Shippable without core changes, at a stated safety cost. The core-seam path is recommended.

### Context propagation — jobs / CLI / events / scheduler

Tenant context is per-execution; it must travel with deferred work.

- **Jobs:** the extension adds its **own** payload integration (a `PropagatesTenant` queue job-middleware). At dispatch it reads the current tenant (via `QueueContextHolder::getContext()` → `TenantContext`) and **writes `tenant_uuid` into the job payload/metadata**; on the worker it **reads that metadata and re-establishes `TenantContext`** (re-validating the tenant is active) before `handle()`, `try/finally` to clear. Note: `QueueContextHolder` only *holds* the current `ApplicationContext` statically for queue helpers — it does **not** serialize per-job metadata — so this capture/restore is the extension's responsibility.
- **CLI:** commands run in **system context** (no tenant) by default; a global `--tenant=<uuid|slug>` sets context for the run; a `tenant:run` / for-each-active-tenant helper for batch operations.
- **Async events/listeners:** same capture/restore as jobs.
- **Scheduler:** a per-tenant scheduling helper (iterate active tenants, set context per run).

### Strategy seam (future hard isolation)

- **`TenancyStrategyInterface`** abstracts how isolation is achieved. v1 ships **`RowLevelStrategy`** as the sole implementation. A future `SchemaStrategy` / `DatabaseStrategy` (hard isolation) is **out of scope for v1** but drops in behind this interface without touching consumers.

## Escape hatches & privileged bypass (noisy by design)

| API | Meaning | Guardrail |
|---|---|---|
| `Tenancy::runAsTenant($tenant, fn)` | Execute `fn` with a specific tenant as current | Explicit |
| `Tenancy::runAsSystem(fn)` | Execute with **no** tenant (central/maintenance work) | Explicit; suspends guard |
| `Tenancy::forAnyTenant(fn)` / `Model::forAnyTenant()` | Cross-tenant read | On request paths, **permission-gated** (config bypass permissions) |
| `Model::withoutTenantScope()` | Drop the scope on one query | Explicit, grep-visible |

No generic `withoutScope()` is exposed for tenancy.

## Configuration — `config/tenancy.php`

```php
return [
    'enabled' => true,
    'resolvers' => ['subdomain', 'path', 'header', 'query', 'jwt', 'active_session'], // ordered
    'subdomain' => ['base_domain' => env('TENANCY_BASE_DOMAIN')],
    'path'      => ['segment' => 't'],            // /t/{tenant}/...
    'header'    => ['name' => 'X-Tenant-Id'],
    'query'     => ['name' => 'tenant_id'],
    'jwt'       => ['claim' => 'tenant_id'],
    'tables'    => [],                            // AUTHORITATIVE list of tenant-owned tables
                                                  // (every BelongsToTenant model's table). Loaded
                                                  // at boot so raw queries are guarded before any
                                                  // model class boots; trait boot is a backstop.
    'enforcement' => [
        'required_by_default' => true,            // BelongsToTenant fails closed
        'guard' => [
            'dev'  => 'throw',                    // dev/test
            'prod' => 'metric',                   // metric | log | off
        ],
    ],
    'bypass_permissions' => ['tenancy.access_any', 'tenancy.manage'],
    'membership' => ['roles' => ['owner', 'admin', 'member', 'viewer']],
];
```

## Console commands

`tenant:create`, `tenant:list`, `tenant:activate`, `tenant:suspend`, `tenant:diagnose` (registry-vs-models drift, unscoped-table audit, membership integrity).

## Component / file layout

```
glueful/tenancy
├── composer.json                       # extra.glueful.provider → TenancyServiceProvider
├── config/tenancy.php
├── migrations/                        # one dir, registered once at FOUNDATION
│   ├── 001_CreateTenantsTable.php              # filename order satisfies the
│   └── 002_CreateTenantMembershipsTable.php    # tenant_uuid → tenants FK
└── src/
    ├── TenancyServiceProvider.php         # services(), register(), boot(), middleware alias 'tenant'
    ├── Context/TenantContext.php               # request-scoped; stored in ApplicationContext::requestState
    ├── Models/{Tenant,TenantMembership}.php
    ├── Resolution/
    │   ├── TenantResolverInterface.php
    │   ├── ResolverChain.php
    │   ├── TenantResolutionPipeline.php         # resolve → validate → set context / fail closed
    │   └── Resolvers/{Subdomain,Path,Header,Query,JwtClaim,ActiveSession}Resolver.php
    ├── Http/TenantMiddleware.php                # alias 'tenant', after auth, try/finally
    ├── ORM/
    │   ├── Concerns/BelongsToTenant.php
    │   └── Scopes/TenantScope.php
    ├── Query/
    │   ├── TenantTableRegistry.php
    │   ├── TenantQueryGuard.php                 # pre-exec interceptor (dev-throw) / post-exec monitor (prod-metric) + auto-inject
    │   └── TenantQuery.php                      # tenantTable()/scope() helpers
    ├── Bypass/Tenancy.php                       # runAsTenant/runAsSystem/forAnyTenant + registerTable
    ├── Authorization/TenantAccess.php           # bypass-permission checks via Gate
    ├── Queue/PropagatesTenant.php               # capture at dispatch / restore on worker
    ├── Strategy/{TenancyStrategyInterface,RowLevelStrategy}.php
    └── Console/{Create,List,Activate,Suspend,Diagnose}*Command.php
```

## Testing strategy

- **Unit:** `TenantScope` injects the predicate; `BelongsToTenant` stamps on create and rejects `tenant_uuid` change; resolver chain ordering; resolution pipeline (inactive tenant → fail closed, non-member → 403, bypass permission → allowed); fail-closed when no tenant.
- **Integration (cross-tenant isolation):** tenant A cannot read/`find`/update/delete tenant B rows (model + relationship paths); raw `db()->table('<registered>')` is auto-scoped; the strict guard **throws** on an intentionally unscoped raw query in the test env; job dispatched in tenant A restores A's context on the worker; `forAnyTenant`/`runAsSystem` bypass works and is permission-gated.
- Harness: framework `TestCase` + `actingWithPermissions()` for bypass tests; SQLite `:memory:`.

## v1 Scope (everything except a hard-isolation adapter)

**In v1:** the **two-seam core PR** (query-builder factory hook + pre-execution interceptor), sequenced first; then the extension — tenants + memberships; `TenantContext` in `ApplicationContext::requestState`; full validated resolver chain (subdomain/path/header/query/jwt/active-session) + membership/permission validation; `BelongsToTenant`/`TenantScope` (scoped reads, stamped immutable writes, fail-closed); `TenantTableRegistry` + **auto-injection on primary table** + **dev/test-throw / prod-metric guard**; noisy bypass APIs + Gate-permission bypass; job/CLI/event/scheduler propagation; `TenancyStrategyInterface` seam with `RowLevelStrategy`; config + five console commands.

**Deferred (own spec later):** an actual schema/DB-per-tenant (hard-isolation) `TenancyStrategy` adapter; deeper query-builder rewriting for joins/raw SQL beyond primary-table auto-injection; tenant admin UI.

## Risks & open items

- **Cross-repo dependency: the two core seams.** Prod-grade enforcement depends on a small framework PR (query-builder factory hook + pre-execution interceptor) landing *before* the extension can fully deliver. The plan must sequence and gate on it. The documented fallback (explicit `tenantTable()` + detection-only guard) keeps the extension shippable if the core PR is delayed, at a stated safety cost.
- **Auto-injection cannot cover every query shape** (joins/raw/unions). Accepted: the guard is the backstop and the docs state the boundary explicitly. The risk is a prod leak in a complex raw path that the prod guard only *logs*. Mitigation: `tenant:diagnose` + metric alerting on `tenancy.unscoped_query`.
- **Post-execution monitoring performance** in prod — per-query SQL inspection has a cost; default prod mode is `metric` and can be set to `off`. Validate overhead during implementation.
- **Tenant-scoped uniqueness** — app-defined unique constraints on tenant-owned tables must be **composite `(tenant_uuid, …)`**, and `DbUnique` validation must be tenant-aware. Provide a `tenantUuid()` schema-helper/snippet and document the pitfall.
- **Resolver/auth ordering** — `tenant` middleware runs after auth, so subdomain/path tenant-awareness is not available pre-auth in v1 (e.g., tenant-specific login theming). Acceptable for v1; a pre-auth lightweight resolver pass is a possible later addition.
