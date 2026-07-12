# Changelog

All notable changes to `glueful/tenancy` will be documented in this file.

The format is based on Keep a Changelog, and this project adheres to Semantic Versioning.

## [Unreleased]

## [2.0.0] - 2026-07-12

**Theme: the provider split** — tenancy's identity/administration control-plane becomes always-on
while request enforcement becomes an explicitly activated provider, and membership role validation
becomes a host-extensible, lock-protected seam.

### Added
- `TenancyControlPlaneProvider` — the always-on control plane. Owns the identity migrations
  (loaded at `MigrationPriority::DEFAULT - 50`), the `TenantProvisioner`,
  `TenantProvisioningRunner`, `TenantAdministration`, `TenantDomainAdministration`, and
  `TenantContextRunner` contract bindings, `ReleasedHostRepository`, the `tenant:*` console
  commands, and the `tenancy` config defaults. Binding presence here answers "is this service
  available?" — never "is tenant enforcement active?" (that signal belongs to the host).
- `MembershipRoleAuthority` + `MembershipRoleLock` — engine-local seams for membership role
  validation and per-(tenant, role) serialization, with back-compatible defaults bound in the
  control-plane provider: `ConfigRoleAuthority` (byte-identical to the previous
  `tenancy.membership.roles` allowlist check) and `AdvisoryMembershipRoleLock`
  (transaction-scoped advisory locks). Hosts may bind their own authority (e.g. per-tenant
  custom roles) without forking the bridge.
- Locked membership mutations: `addMember()`/`setMemberRole()` now run
  `BEGIN → lock → isAssignable() → re-read → write → COMMIT`. Role **changes** lock the source
  and destination role keys in canonical sorted order and re-read the membership after lock
  acquisition; new memberships lock only the destination and retry once on a unique-violation
  race. Persistent concurrent conflicts throw `MembershipRoleConflictException`.

### Changed
- `TenancyServiceProvider` is now **enforcement-only**: the resolver chain/pipeline and profiles,
  tenant request middleware, table registry, enforcement/resolution probes, strategy, and the
  runtime hooks. Its `boot()` hooks (table hook, query guard, insert stamper, registry load)
  register **unconditionally** — the `config('tenancy.enabled')` gate is removed; provider
  presence now means "enforcement machinery loaded".
- `assertRole()` is replaced by the `MembershipRoleAuthority` seam (the default binding preserves
  the exact previous behavior and error message).

### Upgrade Notes (BREAKING)
- **Hosts must register `Glueful\Extensions\Tenancy\TenancyControlPlaneProvider` in
  `config/serviceproviders.php`** (the always-loaded application provider list). It cannot be
  managed by `extensions:enable` — the extension manifest permits one provider per package, and
  that slot belongs to the enforcement provider. Without the control-plane provider there are no
  identity migrations and no provisioning/administration services.
- Registering `TenancyServiceProvider` now loads enforcement unconditionally. Hosts gate
  enforcement by **registration** — adding/removing the provider from `config/extensions.php` at
  their persisted enablement transition — not by setting `tenancy.enabled` config.
- Membership mutations serialize on per-(tenant, role) advisory locks and may throw
  `MembershipRoleConflictException` under persistent concurrent role changes (map to HTTP 409).
- Dependency pins are unchanged: `glueful/extension-contracts ^1.3.0`; framework floor
  `>=1.67.0`.

## [1.3.0] - 2026-07-11

**Theme: closing the workspace lifecycle loop** — reversible two-phase workspace deletion, a
host-cooldown ledger that stops a freed custom domain from being silently reclaimed, and background
re-verification that revokes a verified domain whose DNS proof has drifted. All identity/domain
transitions stay behind neutral contracts, dispatch framework events after commit, and leave
single-tenant and bootstrap installs untouched.

### Added
- Two-phase workspace deletion (`deleteTenant` → `restoreTenant` / `beginPurge` →
  `purgeTenantRecord`): guarded status transitions that refuse the final workspace or one owning a
  required default host, persist the prior status + a restore deadline (`deleted_from_status` /
  `purge_after`, folded into `001`), and hard-delete the tenant record in a single transaction that
  tombstones every host rather than relying on FK cascade.
- Host-cooldown ledger (`004_CreateReleasedHostsTable`): a released custom host is tombstoned for a
  configurable cooldown so a different tenant cannot immediately reclaim it. Per-host advisory locking
  serializes claim/release/reclaim; the releasing tenant may reclaim immediately; a superuser may
  override via an atomic override-and-claim. `removeDomain()` and the final purge both route through
  the cooldown-aware release.
- Background domain re-verification: verified custom domains are periodically re-proven via DNS TXT.
  Drift is counted, and a domain is revoked only after **both** a failure threshold and a grace
  duration are exceeded (`verification_status = revoked` stops resolution while leaving the operator's
  enable/disable `status` untouched); recovered proof restores it. Adds folded `003` tracking columns
  (`last_checked_at`, `last_check_status`, `consecutive_failures`, `first_failure_at`) + a sweep index,
  a structured `DnsTxtResult`, and `reverifyDomain()` with a snapshot → DNS-outside-lock → guarded
  apply sequence.
- After-commit events: `TenantDeleted`, `TenantRestored`, `HostReleased`, `DomainReverificationFailed`,
  `DomainRevoked`, `DomainReverified` (framework `BaseEvent`; the verification token never appears in a
  payload).

### Changed
- `removeDomain()` now delegates to the cooldown-aware `releaseDomain()` — there is no public
  hard-delete path that bypasses the cooldown ledger.
- `verifyDomain()` is pending-only (verified/revoked domains use the re-verification lifecycle) and
  moves to the structured DNS lookup so lifecycle decisions never use the lossy list wrapper.

### Upgrade Notes
- Requires `glueful/extension-contracts ^1.3.0`.
- New migration `004_CreateReleasedHostsTable` runs on `migrate:run`.
- `001_CreateTenantsTable` and `003_CreateTenantDomainsTable` gain folded columns + a re-verification
  index. This is a pre-launch fold (fresh installs get the columns directly); no ALTER migration is
  shipped for already-migrated databases.
- New configuration under `tenancy.domains.*` (release cooldown + re-verification) and
  `tenancy.tenants.*` (trash retention + auto-purge, default off). All have safe defaults.

## [1.2.0] - 2026-07-10

**Theme: the tenant identity surface** — verified custom domains, resolver profiles for
public/admin surfaces, and the administration/provisioning bridges that let a host app manage
tenants entirely through neutral contracts. Profiles stay inert until the host binds
full-resolution readiness, so bootstrap and single-tenant installs are untouched.

### Added
- Globally unique, normalized tenant domains (`tenant_domains`: independent
  `verification_status` DNS fact + `status` operator choice; host normalization with
  IDN→ASCII, port/dot stripping, IP-literal/wildcard rejection) with DNS-TXT verification
  via an injectable `DnsTxtLookup`.
- Public and admin resolution profiles. Public resolution uses exact verified
  domains before subdomain inference; admin resolution collects both header and JWT
  candidates, rejects conflicting selectors, and accepts UUIDs only. A `soft` profile
  variant resolves-if-possible without blocking, for routes whose authoritative gate is
  deeper (e.g. signed blob views).
- Neutral contract bridges for tenant lifecycle + memberships
  (`TenantAdministration` — create lands in `provisioning`; final-active-owner protection
  under row locks), domain administration (`TenantDomainAdministration` — including
  pre-verified operator hosts and required-host protection while full resolution is ready),
  activation-time host probes (`TenantResolutionProbe`), and the privileged
  provisioning-context runner (`TenantProvisioningRunner` — the normal runner correctly
  refuses non-active tenants; seeding a `provisioning` tenant uses this narrow seam).

### Changed
- Subdomain resolution now reads `tenancy.public_origin.base_domain`; the
  duplicated `tenancy.subdomain.base_domain` key is retired.
- Tenant middleware profiles remain inert until full-resolution readiness is
  bound and ready, preserving bootstrap and single-tenant behavior.

### Upgrade Notes
- **`tenancy.subdomain.base_domain` is retired.** Set `tenancy.public_origin.base_domain`
  instead (one shared source for activation, subdomain resolution, normalization, and host
  policy). Installs that never configured `TENANCY_BASE_DOMAIN` are unaffected.
- New migration `003_CreateTenantDomainsTable` runs on `migrate:run`; it is additive and
  engine-portable.

## [1.1.0] - 2026-07-10

**Theme: the neutral-contracts bridge layer + write-side stamping.** The extension now
implements every `glueful/extension-contracts` tenancy seam, so host applications (and their
enablement/retrofit flows) consume tenancy exclusively through interfaces — no concrete
`Glueful\Extensions\Tenancy\*` imports — and builder inserts are stamped with the active
tenant automatically.

### Added
- **Contract bridges** binding the neutral `glueful/extension-contracts` seams to the
  extension's internals (implementers bind; consumers soft-resolve):
  - `ContractTenantResolver` → `CurrentTenantResolver` and `ContractTableRegistry` →
    `TenantTableRegistry` (registration without writing into this extension's config).
  - `ContractTenantProvisioner` → `TenantProvisioner`: stands up the default tenant + active
    owner membership, **idempotent by caller-supplied uuid** (crash-then-retry reuses the same
    tenant); `hasAnyTenant()` detects pre-existing installs. The ONE place a consumer's
    provisioning path crosses into the concrete `Tenant`/`TenantMembership` models.
  - `ContractTenantRunner` → `TenantContextRunner`: run a callable as a given tenant, as the
    system channel, or for each active tenant (seed/sync/background workers). Per-tenant
    failures raise the new `TenantIterationException`, which carries the offending tenant uuid
    so fail-fast callers can report exactly where iteration stopped.
  - `ContractEnforcementProbe` → `TenantEnforcementProbe`: read-side view of the owned-table
    registry (`isRegistered()`/`registeredTables()`) so a host's finalization gate can PROVE
    every owned table is registered in the serving process.
- **Write-side tenant stamping.** `TenantInsertStamper` registers via the framework's new
  `Connection::addInsertHook()` (1.67.0) so every builder `insert()`/`insertBatch()`/`upsert()`
  into a registered tenant-owned table is stamped with the active tenant's `tenant_uuid` in one
  place. No `CurrentContext` (migrations/boot/CLI outside `runAsTenant`) is a documented
  pass-through; the read guard still rejects cross-tenant values.

### Fixed
- **Aliased tenant-owned reads are now scoped.** The read table-hook resolves `table('x AS y')`
  to the real table before the owned check and qualifies the predicate with the alias, so an
  aliased primary table can no longer slip past auto-injection.

### Changed
- Dependency floors: `glueful/extension-contracts` `^1.1.0` (the enablement seams);
  framework `>=1.67.0` (`Connection::addInsertHook` / seam APIs).

## [1.0.2] - 2026-06-16

### Fixed

- Register migration paths during provider boot so `migrate:run` sees the tenancy
  schema through the same CLI lifecycle used by other extension migrations.

## [1.0.1] - 2026-06-13

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
