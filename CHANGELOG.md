# Changelog

All notable changes to `glueful/tenancy` will be documented in this file.

The format is based on Keep a Changelog, and this project adheres to Semantic Versioning.

## [Unreleased]

### Added

- **Discovery-path regression test.** Loads the provider through the framework's real
  extension-discovery dispatch — for this DSL-based provider that means compiling `services()`
  through `DefaultServicesLoader`, which rejects non-array specs. Guards against a typed
  `Definition` object (e.g. the resolver-chain factory) being placed back into `services()`.

### Fixed

- **Security: unauthenticated tenant candidates now fail closed by default.** When a
  request resolves a tenant from the configured resolvers but `auth.user.uuid` is
  absent, the resolution pipeline now denies before setting tenant context
  (`tenancy.enforcement.require_authenticated`, default `true`). This closes the
  misordered/missing-auth route case where a client-controlled tenant header/query/path
  could select any active tenant without a membership check.
- **Security: raw writes cannot plant or reassign rows into another tenant.**
  `TenantQueryGuard` now inspects tenant-owned `INSERT`/`UPDATE` statements that write
  `tenant_uuid` and rejects values different from the active tenant. This closes the
  conservative-heuristic gap where merely mentioning `tenant_uuid` made a raw write look
  scoped while writing a victim tenant id.
- **Security: load-bearing enforcement registration fails loud outside production.**
  `tenancy.tables` is validated during boot and enforcement registration is wrapped so
  non-production environments rethrow instead of silently booting without the table hook
  or query guard. Production logs and continues per the framework extension posture.
- **Queue system jobs can now explicitly use `Tenancy::runAsSystem()`.** No-tenant jobs
  using `PropagatesTenant` now set `CurrentContext` while leaving tenant/bypass empty, so
  trusted maintenance jobs can opt into `runAsSystem()` instead of failing because the
  facade had no current context.
- **Joined raw query-builder reads no longer receive ambiguous tenant predicates.**
  The `Connection::table()` auto-injection hook now qualifies the primary table's
  `tenant_uuid` predicate, so joined reads against another table that also has a
  `tenant_uuid` column do not fail or bind the wrong table.
- **`ForEachTenant` now isolates per-tenant failures.** A thrown exception from one
  tenant's scheduled work is recorded in a `ForEachTenantResult` and does not abort
  later active tenants; the helper still clears tenant/current context after every
  iteration.
- **Tenant lifecycle fields are no longer mass assignable.** `Tenant::$fillable` no
  longer accepts `status`, and `TenantMembership::$fillable` no longer accepts `role`
  or `status`; trusted code should change those fields through explicit commands or
  repository/storage paths.
- **`tenancy.enabled=false` now skips all enforcement registration.** Boot no longer
  registers the table hook or raw-query guard when the extension is disabled, matching
  the documented master-switch behavior.
- **Raw multi-row inserts are checked row-by-row for foreign `tenant_uuid` writes.**
  The write guard now scans every inserted row's tenant binding instead of only the
  first row's binding.
- **Boot compatibility with framework 1.55.** `services()` mixed DSL array specs with a
  strongly-typed `FactoryDefinition` object (for the config-ordered `ResolverChain`). The
  framework's DSL service loader rejects non-array specs (`"Service '<id>' must be an array"`),
  so the object entry threw during boot in dev/test (and dropped bindings in production) under
  framework 1.55. The resolver chain is now bound via a named (non-closure, production-safe) DSL
  factory — `[ResolverFactory::class, 'chainFromContainer']` — keeping the whole `services()` map
  pure DSL. Adds `ResolverFactory::chainFromContainer()` as the container adapter.

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
