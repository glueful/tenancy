# Changelog

All notable changes to `glueful/tenancy` will be documented in this file.

The format is based on Keep a Changelog, and this project adheres to Semantic Versioning.

## [1.0.0] - 2026-06-08

First release. **Shared-database, row-level (table-relationship) multi-tenancy** for Glueful: tenant-owned
tables carry a `tenant_uuid` column and every read/write against them is automatically scoped to the
active tenant. Requires **`glueful/framework ^1.53.0`** — the chainable `Connection::addTableHook()` /
`QueryExecutor::addQueryInterceptor()` seams plus the `Connection::class` container binding.

### Added

- **Tenant registry & memberships** — `tenants` and `tenant_memberships` migrations +
  `Tenant` / `TenantMembership` models. **Many-tenants-per-user** by default: a (global) user is granted a
  coarse role (`owner` / `admin` / `member` / `viewer`) in a tenant via a membership row. Uses
  `tenant_uuid` throughout; `user_uuid` is indexed only — **no** FK into the user store (separate package).
- **Validated tenant resolution** — the `tenant` middleware resolves the active tenant through an ordered
  resolver chain (`subdomain`, `path`, `header`, `query`, `jwt`, `active_session`), validates existence +
  active status + membership (or bypass), sets request-scoped context, and clears it in a `finally`.
  **404** for unknown/inactive tenants, **403** for a known tenant with no membership, and optional
  `hide_existence` to collapse 403 → 404 so membership can't be probed.
- **Automatic ORM scoping** — the `BelongsToTenant` trait adds a global scope that constrains reads to the
  active tenant, **force-stamps** `tenant_uuid` on create (overriding any caller-supplied value, even when
  mass-assigned), and makes `tenant_uuid` **immutable** on update. Fails closed when no tenant context is
  present. The predicate is written **unqualified** so scoped bulk `update()` / `delete()` work on the
  framework's write path.
- **Non-ORM enforcement** — a chainable `Connection::table()` hook auto-injects the tenant predicate into
  raw query-builder access against registered tenant-owned tables, backed by a pre-execution
  `TenantQueryGuard` interceptor that catches unscoped raw SQL (**throws in dev/test, logs in prod**). The
  authoritative tenant-owned table list lives in `config/tenancy.php`.
- **Explicit bypass facade** — `Tenancy::runAsTenant()` / `runAsSystem()` / `forAnyTenant()` /
  `registerTable()` (intentionally noisy — no generic `withoutScope()`). `forAnyTenant` on request paths is
  **permission-gated**: it consults the app's active permission provider (e.g. the `glueful/aegis` RBAC
  extension) for `config('tenancy.bypass_permissions')` (default `tenancy.access_any` / `tenancy.manage`),
  falling back to the framework `Gate`'s voters; denials fail closed.
- **Context propagation** — `PropagatesTenant` (opt-in per job: capture the tenant at dispatch, re-validate
  it is active and restore it on the worker), `RunsInTenantContext` (CLI `--tenant`; no flag = system
  context), and `ForEachTenant` (run a callback once per active tenant from the scheduler).
- **Console commands** — `tenant:create`, `tenant:list`, `tenant:activate`, `tenant:suspend`, and
  `tenant:diagnose` (reports registered tenant-owned tables, schema drift, and membership integrity).
- **Strategy seam** — `TenancyStrategyInterface` + `RowLevelStrategy` (the only v1 strategy), leaving room
  for schema- or database-per-tenant strategies later without changing call sites.

### Security

- **Logical isolation, not hard isolation.** All tenants share one database; isolation is enforced in the
  application layer (global scope + force-stamp + raw-query guard), so hand-written SQL that bypasses the
  framework query layer is not protected — see the README "Security posture". Defaults fail closed
  (tenant-required, force-stamped writes, dev-throwing guard). A cross-tenant isolation **acceptance suite**
  asserts the contract: an A-member can never read or write tenant B's rows, and cross-tenant access
  requires an explicit bypass permission.
